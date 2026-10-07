<?php

namespace Tests\Feature\Entrega;

use App\Models\Auditoria;
use App\Models\Entrega;
use App\Models\Rol;
use App\Models\Ticket;
use App\Models\Transcripcion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** El acta que firma el paciente: entregado con lote, pendientes, no dispensado con motivo y firma. */
class ActaEntregaTest extends TestCase
{
    use RefreshDatabase;

    private Entrega $entrega;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.inventario.url' => 'http://inventario.test', 'services.inventario.token' => 'x']);

        Rol::create(['nombre' => 'FARMACIA', 'permisos' => ['entrega' => ['ver']]]);
        $ticket = Ticket::factory()->create();
        $this->usuario = User::factory()->create(['roles' => ['FARMACIA'], 'sede_id' => $ticket->sede_id, 'nombre' => 'Ana Farmacia']);

        // La transcripción del ticket: la fórmula #2 no se dispensa porque está vencida.
        $ruta = UploadedFile::fake()->image('o.jpg')->store('soportes/ordenes-medicas', 'local');
        $soporte = $ticket->paciente->soportes()->create(['ticket_id' => $ticket->id, 'orden_medica' => $ruta, 'alto_costo_oncologico' => false, 'cargado_por' => $this->usuario->id]);
        $t = Transcripcion::where('soporte_id', $soporte->id)->sole();
        $t->update(['estado' => Transcripcion::ESTADO_CONFIRMADA, 'ticket_id' => $ticket->id, 'formula' => ['formulas' => [
            ['numero' => 1, 'ips' => 'CS CISAMF'],
            ['numero' => 2, 'ips' => 'METROSALUD', 'rechazada' => true, 'motivo_rechazo' => 'vencida'],
        ]]]);
        $t->items()->createMany([
            ['formula' => 1, 'texto_prescrito' => 'Losartan 50 mg', 'codigo_inventario' => 'MX377-6', 'cantidad_mes' => 30],
            ['formula' => 2, 'texto_prescrito' => 'Levotiroxina 25 mcg tableta'],
        ]);

        Storage::disk('local')->put('soportes/firmas-entrega/1.png', 'PNG-FIRMA');
        $this->entrega = Entrega::create([
            'ticket_numero' => $ticket->numero, 'paciente_id' => $ticket->paciente_id, 'sede_id' => $ticket->sede_id, 'usuario_id' => $this->usuario->id,
            'tipo' => Entrega::TIPO_PRESENCIAL, 'receptor_nombre' => 'Hija de la paciente', 'receptor_documento' => '99887766',
            'receptor_parentesco' => 'Hija', 'firma_path' => 'soportes/firmas-entrega/1.png',
        ]);
        $this->entrega->items()->createMany([
            ['codigo' => 'MX377-6', 'nombre' => 'LOSARTAN 50 MG', 'cantidad_solicitada' => 30, 'cantidad_entregada' => 20, 'cantidad_pendiente' => 10, 'resultado' => 'parcial', 'motivo' => 'Sin existencias en la sede'],
        ]);
    }

    public function test_el_acta_trae_lotes_pendientes_lo_no_dispensado_y_la_firma(): void
    {
        Http::fake(['inventario.test/api/movimientos*' => Http::response(['data' => [
            ['codigo' => 'MX377-6', 'numero_lote' => 'L-LOS-1', 'fecha_vencimiento' => '2027-03-31', 'cantidad' => -20],
        ]])]);

        $html = $this->actingAs($this->usuario)->get(route('entregas.acta', $this->entrega))->assertOk()->getContent();

        $this->assertStringContainsString('L-LOS-1', $html);                         // lote de lo entregado
        $this->assertStringContainsString('31/03/2027', $html);
        $this->assertStringContainsString('Sin existencias en la sede', $html);       // pendiente con motivo
        $this->assertStringContainsString('Levotiroxina 25 mcg tableta', $html);      // no dispensado…
        $this->assertStringContainsString('Fórmula vencida', $html);                  // …con su motivo
        $this->assertStringContainsString('Fui informado de los medicamentos que no se dispensan', $html);
        $this->assertStringContainsString('data:image/png;base64,'.base64_encode('PNG-FIRMA'), $html); // firma incrustada, sin URL
        $this->assertStringNotContainsString('Losartan 50 mg</td>', $html);           // lo de la fórmula buena no aparece como no dispensado
        Http::assertSent(fn ($r) => str_contains($r->url(), 'referencia=ENTREGA-'.$this->entrega->id));
        $this->assertDatabaseHas('auditorias', ['accion' => Auditoria::ACCION_ABRIO_ACTA_ENTREGA, 'entidad_id' => $this->entrega->id]);
    }

    public function test_sin_inventario_el_acta_sale_igual_con_aviso(): void
    {
        Http::fake(['inventario.test/*' => Http::response('caído', 500)]);

        $this->actingAs($this->usuario)->get(route('entregas.acta', $this->entrega))
            ->assertOk()
            ->assertSee('No se pudo consultar el inventario');
    }

    public function test_de_otra_sede_o_sin_permiso_no_entra(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['FARMACIA']]))->get(route('entregas.acta', $this->entrega))->assertForbidden();
        $this->actingAs(User::factory()->create(['roles' => [], 'sede_id' => $this->entrega->sede_id]))->get(route('entregas.acta', $this->entrega))->assertForbidden();
    }
}
