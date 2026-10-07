<?php

namespace Tests\Feature\Transcripcion;

use App\Models\Auditoria;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Ticket;
use App\Models\Transcripcion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Fase 3: la orden de dispensación imprimible del ticket. */
class OrdenEntregaTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Sin Http::fake() general: el primero que coincide gana y taparía los fakes de cada prueba.
        Http::preventStrayRequests();

        Rol::create(['nombre' => 'TRANSCRIPCION', 'permisos' => ['transcripcion' => ['ver']]]);
        $this->ticket = Ticket::factory()->create();
        $this->usuario = User::factory()->create(['roles' => ['TRANSCRIPCION'], 'sede_id' => $this->ticket->sede_id]);
        $this->ticket->sede->update(['codigo' => 'LA30']);

        // Dos órdenes médicas en el mismo ticket; la primera trae dos fórmulas en la misma imagen.
        $this->transcripcion([['numero' => 1, 'ips' => 'METROSALUD'], ['numero' => 2, 'ips' => 'INSTITUTO DEL CORAZON']], [
            ['formula' => 1, 'texto_prescrito' => 'Losartan 50 mg', 'codigo_inventario' => 'MX377-6', 'nombre_inventario' => 'LOSARTAN 50 MG TABLETA', 'posologia' => '1 tableta cada 12 horas', 'cantidad_total' => 180, 'meses' => 3, 'entrega_mes' => 1, 'cantidad_mes' => 60],
            ['formula' => 2, 'texto_prescrito' => 'Timolol 0.5% gotas', 'codigo_inventario' => 'DEMO-GOT-1', 'nombre_inventario' => 'TIMOLOL 0.5 %', 'posologia' => '1 gota cada 12 horas', 'meses' => 1, 'entrega_mes' => 1, 'cantidad_mes' => 2],
        ]);
        $this->transcripcion([['numero' => 1, 'ips' => 'CLINICA ENVIGADO']], [
            ['formula' => 1, 'texto_prescrito' => 'Etoricoxib 90 mg', 'codigo_inventario' => 'MOCK-9', 'nombre_inventario' => 'ETORICOXIB 90 MG', 'posologia' => '1 diaria', 'meses' => 1, 'entrega_mes' => 1, 'cantidad_mes' => 10],
        ]);
    }

    private function transcripcion(array $formulas, array $items, string $estado = Transcripcion::ESTADO_CONFIRMADA): void
    {
        $ruta = UploadedFile::fake()->image('o.jpg')->store('soportes/ordenes-medicas', 'local');
        $soporte = $this->ticket->paciente->soportes()->create([
            'ticket_id' => $this->ticket->id, 'orden_medica' => $ruta, 'alto_costo_oncologico' => false, 'cargado_por' => User::factory()->create()->id,
        ]);
        $t = Transcripcion::where('soporte_id', $soporte->id)->sole();
        $t->update(['estado' => $estado, 'ticket_id' => $this->ticket->id, 'formula' => ['formulas' => $formulas], 'confirmada_por' => $this->usuario->id]);
        $t->items()->createMany($items);
    }

    public function test_cada_medicamento_con_su_formula_real(): void
    {
        $html = $this->actingAs($this->usuario)->get(route('tickets.orden-entrega', $this->ticket))->assertOk()->getContent();

        // Las fórmulas se numeran de corrido en el ticket: 1 y 2 de la primera imagen, 3 de la segunda.
        $this->assertStringContainsString('FÓRMULA #1: METROSALUD', $html);
        $this->assertStringContainsString('FÓRMULA #2: INSTITUTO DEL CORAZON', $html);
        $this->assertStringContainsString('FÓRMULA #3: CLINICA ENVIGADO', $html);
        $this->assertMatchesRegularExpression('/Fór\. #2<\/span>\s*<strong>TIMOLOL/', $html);
        $this->assertMatchesRegularExpression('/Fór\. #3<\/span>\s*<strong>ETORICOXIB/', $html);
        $this->assertStringContainsString('1 gota cada 12 horas', $html); // posología real, no la concentración
        $this->assertStringContainsString('saldo después: 120', $html);
        $this->assertStringNotContainsString('BORRADOR', $html);

        $this->assertDatabaseHas('auditorias', ['accion' => Auditoria::ACCION_IMPRIMIO_ORDEN_ENTREGA, 'entidad_id' => $this->ticket->id]);
    }

    public function test_lo_que_no_hay_en_la_sede_va_a_pendientes_de_domicilio(): void
    {
        config(['services.inventario.url' => 'http://inventario.test', 'services.inventario.token' => 'x']);
        Http::fake(['inventario.test/api/productos/*' => fn ($r) => Http::response(match (true) {
            str_contains($r->url(), '/productos/MX377-6') => ['stock_dispensable' => 40],   // pide 60: 40 en ventanilla, 20 pendientes
            str_contains($r->url(), '/productos/DEMO-GOT-1') => ['stock_dispensable' => 5],
            default => ['stock_dispensable' => 0],           // etoricoxib: nada
        })]);

        $vista = $this->actingAs($this->usuario)->get(route('tickets.orden-entrega', $this->ticket))->assertOk();

        $this->assertSame([40, 2], $vista->viewData('ventanilla')->pluck('entrega')->all());
        $this->assertSame(['MX377-6' => 20, 'MOCK-9' => 10], $vista->viewData('pendientes')->pluck('falta', 'codigo')->all());
        $vista->assertSee('Pendiente: sin existencias en la sede');
    }

    public function test_si_falta_confirmar_alguna_sale_como_borrador(): void
    {
        $this->transcripcion([['numero' => 1]], [], Transcripcion::ESTADO_LEIDA);

        $this->actingAs($this->usuario)->get(route('tickets.orden-entrega', $this->ticket))->assertSee('BORRADOR: 1 fórmula');
    }

    public function test_sin_permiso_o_de_otra_sede_no_entra(): void
    {
        $this->actingAs(User::factory()->create(['roles' => []]))->get(route('tickets.orden-entrega', $this->ticket))->assertForbidden();
        $this->actingAs(User::factory()->create(['roles' => ['TRANSCRIPCION']]))->get(route('tickets.orden-entrega', $this->ticket))->assertForbidden();
    }
}
