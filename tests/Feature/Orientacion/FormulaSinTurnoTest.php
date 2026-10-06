<?php

namespace Tests\Feature\Orientacion;

use App\Filament\Pages\Orientacion;
use App\Models\Cola;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Soporte;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La fórmula que quedó guardada sin turno.
 *
 * Pasa cuando la sede no tenía colas activas: la fórmula se guarda igual
 * —perderla sería peor que quedarse sin turno— y después hay que poder
 * completarla **sin volver a tomar las fotos**.
 */
class FormulaSinTurnoTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private User $orientador;

    private Paciente $paciente;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake('local');

        config([
            'savia.username' => '900000000',
            'savia.password' => 'clave-de-prueba',
            'savia.token' => null,
            'savia.auditar' => false,
        ]);

        $this->sede = Sede::factory()->create(['nombre' => 'PREMIUM PLAZA', 'codigo' => 'PRP']);

        Rol::create(['nombre' => 'ORIENTADOR', 'permisos' => [
            'orientacion' => ['usar', 'ver_orden'],
            'tickets' => ['imprimir'],
        ]]);

        $this->orientador = User::factory()->create([
            'roles' => ['ORIENTADOR'],
            'sede_id' => $this->sede->id,
        ]);

        $this->paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
        ]);

        $this->actingAs($this->orientador);

        Http::fake([
            '*rest/token/generacion' => Http::response(['access_token' => 'TOKEN-A', 'expires_in' => 3600]),
            '*rest/afiliado/consultar-afiliado' => Http::response([
                'registros' => 1,
                'codigo' => 0,
                'mensaje' => 'Afiliado encontrado',
                'afiliados' => [[
                    'tipoDocumentoAfiliado' => 'CC',
                    'documentoAfiliado' => '1017234567',
                    'primerNombreAfiliado' => 'MARIA',
                    'primerApellidoAfiliado' => 'GOMEZ',
                    'fechaNacimientoAfiliado' => '1990-05-14',
                    'estadoAfiliacion' => 'Activo',
                    'telefonoMovil' => '3001234567',
                    'direccion' => 'KR 40 70A 23',
                    'descripcionCiudadResidencia' => 'MEDELLÍN',
                    'discapacidad' => 'NO',
                ]],
            ]),
        ]);
    }

    /* ------------------------------------------------------------------ *
     *  Andamiaje
     * ------------------------------------------------------------------ */

    private function colaActiva(): Cola
    {
        return Cola::factory()->create([
            'sede_id' => $this->sede->id,
            'nombre' => 'Dispensación general',
            'prefijo' => 'A',
            'orden' => 1,
            'activa' => true,
            'atiende_alto_costo' => false,
        ]);
    }

    /**
     * Una hoja guardada sin ticket, como la deja una sede sin colas.
     */
    private function hojaSinTurno(int $pagina = 1, ?string $cargadaEl = null): Soporte
    {
        $ruta = 'soportes/ordenes-medicas/sin-turno-'.uniqid().'.jpg';
        Storage::disk('local')->put($ruta, 'bytes');

        $soporte = $this->paciente->soportes()->create([
            'ticket_id' => null,
            'orden_medica' => $ruta,
            'pagina' => $pagina,
            'mime' => 'image/jpeg',
            'cargado_por' => $this->orientador->id,
        ]);

        if ($cargadaEl !== null) {
            $soporte->forceFill(['created_at' => $cargadaEl])->saveQuietly();
        }

        return $soporte->fresh();
    }

    private function consultar(): Testable
    {
        return Livewire::test(Orientacion::class)
            ->fillForm(['tipo_documento' => 'CC', 'numero_documento' => '1017234567'], 'formularioBusqueda')
            ->call('buscar');
    }

    /* ------------------------------------------------------------------ *
     *  El aviso
     * ------------------------------------------------------------------ */

    public function test_avisa_cuando_el_paciente_tiene_una_formula_sin_turno(): void
    {
        $this->hojaSinTurno(1);
        $this->hojaSinTurno(2);

        $this->consultar()
            ->assertSet('hojasSinTurno', 2)
            ->assertSee('Tiene una fórmula sin turno');
    }

    public function test_sin_formulas_sueltas_no_aparece_el_aviso(): void
    {
        $this->consultar()
            ->assertSet('hojasSinTurno', 0)
            ->assertDontSee('Tiene una fórmula sin turno');
    }

    /**
     * Si la sede estuvo mal configurada dos días distintos, son dos fórmulas
     * distintas: juntarlas en un ticket mezclaría dos atenciones.
     */
    public function test_solo_ofrece_las_hojas_de_la_carga_mas_reciente(): void
    {
        $this->hojaSinTurno(1, now()->subDays(5)->toDateTimeString());
        $this->hojaSinTurno(1, now()->toDateTimeString());
        $this->hojaSinTurno(2, now()->toDateTimeString());

        $this->consultar()->assertSet('hojasSinTurno', 2);
    }

    /* ------------------------------------------------------------------ *
     *  Completarla
     * ------------------------------------------------------------------ */

    public function test_genera_el_turno_sin_volver_a_tomar_las_fotos(): void
    {
        $this->colaActiva();

        $primera = $this->hojaSinTurno(1);
        $segunda = $this->hojaSinTurno(2);

        $this->consultar()->call('completarFormulaSinTurno');

        $ticket = Ticket::sole();

        $this->assertSame($ticket->id, $primera->fresh()->ticket_id);
        $this->assertSame($ticket->id, $segunda->fresh()->ticket_id);

        // No se duplicó ninguna hoja: son las mismas de antes.
        $this->assertSame(2, Soporte::count());
    }

    public function test_las_hojas_recuperadas_se_renumeran_desde_uno(): void
    {
        $this->colaActiva();

        // Dos cargas del mismo día, cada una empezando en 1.
        $this->hojaSinTurno(1);
        $this->hojaSinTurno(1);
        $this->hojaSinTurno(2);

        $this->consultar()->call('completarFormulaSinTurno');

        $this->assertSame(
            [1, 2, 3],
            Ticket::sole()->soportes()->get()->pluck('pagina')->all(),
        );
    }

    public function test_el_turno_nuevo_es_de_la_sede_de_quien_atiende(): void
    {
        $this->colaActiva();
        $this->hojaSinTurno();

        $this->consultar()->call('completarFormulaSinTurno');

        $ticket = Ticket::sole();

        $this->assertSame($this->sede->id, $ticket->sede_id);
        $this->assertSame($this->paciente->id, $ticket->paciente_id);
        $this->assertStringStartsWith('TK-PRP-', $ticket->numero);
        $this->assertSame(Ticket::ESTADO_GENERADO, $ticket->estado);
    }

    public function test_respeta_la_prioridad_que_este_en_pantalla(): void
    {
        $this->colaActiva();
        $this->hojaSinTurno();

        $this->consultar()
            ->fillForm([
                'prioridad' => Ticket::PRIORIDAD_PREFERENCIAL,
                'motivo_prioridad' => 'gestante',
            ], 'formularioVisita')
            ->call('completarFormulaSinTurno');

        $ticket = Ticket::sole();

        $this->assertSame(Ticket::PRIORIDAD_PREFERENCIAL, $ticket->prioridad);
        $this->assertSame('gestante', $ticket->motivo_prioridad);
    }

    /**
     * Las fotos ya están y el contacto se confirmó el día que se cargaron:
     * exigirlo otra vez impediría recuperar nada.
     */
    public function test_no_exige_fotos_nuevas_ni_volver_a_confirmar_el_contacto(): void
    {
        $this->colaActiva();
        $this->hojaSinTurno();

        $this->consultar()
            ->call('completarFormulaSinTurno')
            ->assertHasNoFormErrors([], 'formularioVisita');

        $this->assertSame(1, Ticket::count());
    }

    public function test_tras_completarla_el_aviso_desaparece_y_se_ve_el_turno(): void
    {
        $this->colaActiva();
        $this->hojaSinTurno();

        $pagina = $this->consultar()->call('completarFormulaSinTurno');

        $pagina->assertSet('hojasSinTurno', 0)
            ->assertSet('ticketGeneradoId', Ticket::sole()->id)
            ->assertSee(Ticket::sole()->turno);
    }

    /* ------------------------------------------------------------------ *
     *  Cuando todavía no se puede
     * ------------------------------------------------------------------ */

    public function test_si_la_sede_sigue_sin_colas_la_formula_no_se_pierde(): void
    {
        $hoja = $this->hojaSinTurno();

        $this->consultar()->call('completarFormulaSinTurno');

        $this->assertSame(0, Ticket::count());
        $this->assertNull($hoja->fresh()->ticket_id);
        // Y la hoja sigue en el disco, esperando.
        Storage::disk('local')->assertExists($hoja->orden_medica);
    }

    public function test_completar_dos_veces_no_genera_dos_turnos(): void
    {
        $this->colaActiva();
        $this->hojaSinTurno();

        $pagina = $this->consultar();

        $pagina->call('completarFormulaSinTurno');
        $pagina->call('completarFormulaSinTurno');

        $this->assertSame(1, Ticket::count());
    }
}
