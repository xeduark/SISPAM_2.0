<?php

namespace Tests\Feature\Transcripcion;

use App\Filament\Resources\TranscripcionResource\Pages\RevisarTranscripcion as Pantalla;
use App\Models\Auditoria;
use App\Models\Entrega;
use App\Models\Rol;
use App\Models\Ticket;
use App\Models\Transcripcion;
use App\Models\User;
use App\Services\Transcripcion\RevisarTranscripcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** Fase 4: reabrir una transcripción confirmada, corregirla y que la orden salga como nueva versión. */
class RectificacionTest extends TestCase
{
    use RefreshDatabase;

    private Transcripcion $t;

    private Ticket $ticket;

    private User $lider;

    private RevisarTranscripcion $reglas;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();

        Rol::create(['nombre' => 'LIDER', 'permisos' => ['transcripcion' => ['ver', 'transcribir', 'rectificar'], 'orientacion' => ['ver_orden']]]);
        $this->ticket = Ticket::factory()->create();
        $this->lider = User::factory()->create(['roles' => ['LIDER'], 'nombre' => 'Lina', 'sede_id' => $this->ticket->sede_id]);
        $this->reglas = app(RevisarTranscripcion::class);

        $ruta = UploadedFile::fake()->image('o.jpg')->store('soportes/ordenes-medicas', 'local');
        $soporte = $this->ticket->paciente->soportes()->create(['ticket_id' => $this->ticket->id, 'orden_medica' => $ruta, 'alto_costo_oncologico' => false, 'cargado_por' => $this->lider->id]);
        $this->t = Transcripcion::where('soporte_id', $soporte->id)->sole();
        $this->t->update([
            'estado' => Transcripcion::ESTADO_CONFIRMADA, 'ticket_id' => $this->ticket->id, 'verificacion_cedula' => Transcripcion::CEDULA_COINCIDE,
            'formula' => ['formulas' => [['numero' => 1, 'ips' => 'CS CISAMF', 'fecha_expedicion' => today()->subDay()->toDateString(), 'medico' => ['nombre' => 'CLAUDIA']]]],
        ]);
        $this->t->items()->create([
            'formula' => 1, 'texto_prescrito' => 'Losartan 50 mg', 'codigo_inventario' => 'MX377-6', 'nombre_inventario' => 'LOSARTAN 50 MG',
            'cantidad_total' => 90, 'meses' => 3, 'entrega_mes' => 1, 'cantidad_mes' => 30, 'revisado' => true,
        ]);
    }

    public function test_rectificar_y_confirmar_sube_la_version_y_deja_antes_y_despues(): void
    {
        $t = $this->reglas->rectificar($this->t, $this->lider, 'Cantidad mal digitada');
        $this->assertSame(Transcripcion::ESTADO_EN_REVISION, $t->estado);
        $this->assertTrue($t->enRectificacion());

        $item = $t->items()->sole();
        $this->reglas->guardar($t->fresh(), $this->lider, $t->formulas(), [
            $item->only(['id', 'formula', 'texto_prescrito', 'codigo_inventario', 'cantidad_total', 'meses', 'entrega_mes', 'revisado']) + ['cantidad_mes' => 60, 'cantidad_total' => 180],
        ]);
        $this->reglas->confirmar($t->fresh(), $this->lider);

        $t = $t->fresh();
        $this->assertSame(Transcripcion::ESTADO_CONFIRMADA, $t->estado);
        $this->assertSame(2, $t->version);
        $this->assertFalse($t->enRectificacion());

        $auditoria = Auditoria::where('accion', Auditoria::ACCION_RECTIFICO_TRANSCRIPCION)->sole();
        $this->assertStringContainsString('Cantidad mal digitada', $auditoria->descripcion);
        $this->assertEquals(30, $auditoria->cambios['antes']['lineas'][0]['cantidad_mes']);
        $this->assertEquals(60, $auditoria->cambios['despues']['lineas'][0]['cantidad_mes']);
        $this->assertArrayNotHasKey('texto_prescrito', $auditoria->cambios['antes']['lineas'][0]); // sin texto clínico

        $this->actingAs($this->lider)->get(route('tickets.orden-entrega', $this->ticket))->assertSee('VERSIÓN 2 — RECTIFICADA');
    }

    public function test_no_se_rectifica_si_ya_hubo_entrega(): void
    {
        Entrega::create(['ticket_numero' => $this->ticket->numero, 'sede_id' => $this->ticket->sede_id, 'usuario_id' => $this->lider->id, 'tipo' => Entrega::TIPO_PRESENCIAL]);

        $this->expectExceptionMessage('Ya hay una entrega de este ticket');
        $this->reglas->rectificar($this->t, $this->lider, 'x');
    }

    public function test_sin_permiso_o_sin_motivo_no_se_rectifica(): void
    {
        Rol::create(['nombre' => 'TRANSCRIPCION', 'permisos' => ['transcripcion' => ['ver', 'transcribir']]]);
        $sinPermiso = User::factory()->create(['roles' => ['TRANSCRIPCION']]);

        try {
            $this->reglas->rectificar($this->t, $sinPermiso, 'x');
            $this->fail('Debió exigir el permiso');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('permiso', $e->getMessage());
        }

        $this->expectExceptionMessage('por qué');
        $this->reglas->rectificar($this->t, $this->lider, '  ');
    }

    public function test_en_rectificacion_no_se_puede_soltar(): void
    {
        $this->reglas->rectificar($this->t, $this->lider, 'Revisar producto');

        $this->expectExceptionMessage('termina confirmando');
        $this->reglas->liberar($this->t->fresh(), $this->lider);
    }

    public function test_boton_rectificar_en_la_pantalla(): void
    {
        $this->actingAs($this->lider);

        Livewire::test(Pantalla::class, ['record' => $this->t->id])
            ->assertActionVisible('rectificar')
            ->callAction('rectificar', ['motivo' => 'Producto equivocado'])
            ->assertHasNoActionErrors()
            ->assertSee('Rectificando la versión 1')
            ->assertActionHidden('liberar')
            ->assertFormFieldIsEnabled('formulas');
    }
}
