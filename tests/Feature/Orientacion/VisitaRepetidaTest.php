<?php

namespace Tests\Feature\Orientacion;

use App\Filament\Pages\Orientacion;
use App\Models\Auditoria;
use App\Models\Cola;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Soporte;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Orientacion\VisitaAbierta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El paciente que vuelve al mostrador.
 *
 * Casi siempre es porque perdió el papel o trae otra hoja de la misma fórmula,
 * y en ninguno de los dos casos necesita otro turno. La pantalla lo avisa
 * antes de generarlo y ofrece las tres salidas.
 */
class VisitaRepetidaTest extends TestCase
{
    use RefreshDatabase;

    private const URL_TOKEN = '*rest/token/generacion';

    private const URL_CONSULTA = '*rest/afiliado/consultar-afiliado';

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

        Cola::factory()->create([
            'sede_id' => $this->sede->id,
            'nombre' => 'Dispensación general',
            'prefijo' => 'A',
            'orden' => 1,
            'activa' => true,
            'atiende_alto_costo' => false,
        ]);

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
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A', 'expires_in' => 3600]),
            self::URL_CONSULTA => Http::response([
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
                    'regimen' => 'SUBSIDIADO',
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

    /**
     * @param  array<string, mixed>  $extra
     */
    private function ticketDelPaciente(array $extra = []): Ticket
    {
        return Ticket::factory()->create(array_merge([
            'paciente_id' => $this->paciente->id,
            'sede_id' => $this->sede->id,
            'fecha' => now()->toDateString(),
            'estado' => Ticket::ESTADO_LISTO,
            'creado_por' => $this->orientador->id,
        ], $extra));
    }

    private function consultar(): Testable
    {
        return Livewire::test(Orientacion::class)
            ->fillForm(['tipo_documento' => 'CC', 'numero_documento' => '1017234567'], 'formularioBusqueda')
            ->call('buscar');
    }

    /**
     * @param  list<UploadedFile>|null  $archivos
     */
    private function conFormula(Testable $pagina, ?array $archivos = null): Testable
    {
        return $pagina->fillForm([
            'contacto_confirmado' => true,
            'orden_medica' => $archivos ?? [UploadedFile::fake()->image('hoja.jpg')],
        ], 'formularioVisita');
    }

    /* ------------------------------------------------------------------ *
     *  Cuándo se avisa
     * ------------------------------------------------------------------ */

    public function test_avisa_cuando_el_paciente_ya_tiene_un_turno_de_hoy(): void
    {
        $ticket = $this->ticketDelPaciente();

        $this->consultar()
            ->assertSet('visitaAbiertaId', $ticket->id)
            ->assertSet('visitaAbiertaMotivo', VisitaAbierta::MOTIVO_EN_CURSO)
            ->assertSee('Este paciente ya tiene una visita hoy')
            ->assertSee($ticket->turno);
    }

    public function test_avisa_cuando_le_quedaron_pendientes_de_otro_dia(): void
    {
        $ticket = $this->ticketDelPaciente([
            'fecha' => now()->subDays(4)->toDateString(),
            'estado' => Ticket::ESTADO_PARCIAL,
        ]);

        $this->consultar()
            ->assertSet('visitaAbiertaId', $ticket->id)
            ->assertSet('visitaAbiertaMotivo', VisitaAbierta::MOTIVO_PENDIENTES)
            ->assertSee('Este paciente tiene medicamentos pendientes');
    }

    public function test_el_turno_de_hoy_manda_sobre_el_parcial_viejo(): void
    {
        $this->ticketDelPaciente([
            'fecha' => now()->subDays(4)->toDateString(),
            'estado' => Ticket::ESTADO_PARCIAL,
        ]);

        $deHoy = $this->ticketDelPaciente();

        $this->consultar()
            ->assertSet('visitaAbiertaId', $deHoy->id)
            ->assertSet('visitaAbiertaMotivo', VisitaAbierta::MOTIVO_EN_CURSO);
    }

    /* ------------------------------------------------------------------ *
     *  Cuándo no se avisa
     * ------------------------------------------------------------------ */

    public function test_un_paciente_nuevo_no_dispara_el_aviso(): void
    {
        $this->paciente->delete();

        $this->consultar()
            ->assertSet('visitaAbiertaId', null)
            ->assertDontSee('Este paciente ya tiene una visita hoy');
    }

    public function test_un_ticket_ya_entregado_no_es_una_visita_viva(): void
    {
        $this->ticketDelPaciente([
            'estado' => Ticket::ESTADO_ENTREGADO,
            'cerrado_en' => now(),
        ]);

        $this->consultar()->assertSet('visitaAbiertaId', null);
    }

    public function test_un_ticket_anulado_tampoco(): void
    {
        $this->ticketDelPaciente([
            'estado' => Ticket::ESTADO_ANULADO,
            'motivo_anulacion' => 'prueba',
            'cerrado_en' => now(),
        ]);

        $this->consultar()->assertSet('visitaAbiertaId', null);
    }

    public function test_un_ticket_de_ayer_sin_atender_no_se_arrastra(): void
    {
        // Lo vence el cierre del día; no es una visita que siga viva.
        $this->ticketDelPaciente([
            'fecha' => now()->subDay()->toDateString(),
            'estado' => Ticket::ESTADO_LISTO,
        ]);

        $this->consultar()->assertSet('visitaAbiertaId', null);
    }

    /**
     * Reimprimir, sumar hojas y entregar exigen la propia sede, así que avisar
     * de un ticket ajeno sería ruido sobre el que nadie puede hacer nada.
     */
    public function test_una_visita_de_otra_sede_no_se_avisa(): void
    {
        $otra = Sede::factory()->create(['nombre' => 'AVENTURA', 'codigo' => 'AVT']);

        $this->ticketDelPaciente(['sede_id' => $otra->id]);

        $this->consultar()->assertSet('visitaAbiertaId', null);
    }

    /* ------------------------------------------------------------------ *
     *  Sumar las hojas a la visita que ya existe
     * ------------------------------------------------------------------ */

    public function test_sumar_las_fotos_no_consume_otro_turno(): void
    {
        $ticket = $this->ticketDelPaciente();

        $this->conFormula($this->consultar())
            ->call('sumarAVisitaAbierta')
            ->assertHasNoFormErrors([], 'formularioVisita');

        $this->assertSame(1, Ticket::count());
        $this->assertSame($ticket->id, Soporte::sole()->ticket_id);
    }

    public function test_las_hojas_nuevas_siguen_la_numeracion_donde_iba(): void
    {
        $ticket = $this->ticketDelPaciente();

        // La visita ya traía dos hojas.
        foreach ([1, 2] as $pagina) {
            $this->paciente->soportes()->create([
                'ticket_id' => $ticket->id,
                'orden_medica' => "soportes/ordenes-medicas/previa-{$pagina}.jpg",
                'pagina' => $pagina,
                'cargado_por' => $this->orientador->id,
            ]);
        }

        $this->conFormula($this->consultar(), [
            UploadedFile::fake()->image('tercera.jpg'),
            UploadedFile::fake()->image('cuarta.jpg'),
        ])->call('sumarAVisitaAbierta');

        $this->assertSame(
            [1, 2, 3, 4],
            $ticket->soportes()->get()->pluck('pagina')->all(),
        );
    }

    public function test_sumar_tambien_actualiza_el_contacto_confirmado(): void
    {
        $this->ticketDelPaciente();

        $this->consultar()
            ->fillForm([
                'contacto_confirmado' => true,
                'telefono_movil' => '3159998877',
                'orden_medica' => [UploadedFile::fake()->image('hoja.jpg')],
            ], 'formularioVisita')
            ->call('sumarAVisitaAbierta');

        $this->paciente->refresh();
        $this->assertSame('3159998877', $this->paciente->telefono_movil);
        $this->assertNotNull($this->paciente->contacto_confirmado_at);
    }

    /**
     * El id de la visita viaja en el estado de Livewire. Cambiarlo a mano
     * colgaría una fórmula del ticket de otra persona.
     */
    public function test_no_se_pueden_sumar_hojas_a_la_visita_de_otro_paciente(): void
    {
        $this->ticketDelPaciente();

        $ajeno = Ticket::factory()->create([
            'paciente_id' => Paciente::factory()->create()->id,
            'sede_id' => $this->sede->id,
            'fecha' => now()->toDateString(),
            'estado' => Ticket::ESTADO_LISTO,
            'creado_por' => $this->orientador->id,
        ]);

        $this->conFormula($this->consultar())
            ->set('visitaAbiertaId', $ajeno->id)
            ->call('sumarAVisitaAbierta');

        // No se coló ninguna hoja en el ticket ajeno.
        $this->assertSame(0, Soporte::where('ticket_id', $ajeno->id)->count());
    }

    /* ------------------------------------------------------------------ *
     *  Generar otro de todos modos
     * ------------------------------------------------------------------ */

    public function test_se_puede_generar_otro_turno_si_trae_formula_distinta(): void
    {
        $primero = $this->ticketDelPaciente();

        $this->conFormula($this->consultar())
            ->call('generarDeTodosModos')
            ->assertHasNoFormErrors([], 'formularioVisita');

        $this->assertSame(2, Ticket::count());

        $segundo = Ticket::where('id', '!=', $primero->id)->sole();
        $this->assertSame($segundo->id, Soporte::sole()->ticket_id);
        $this->assertSame(1, Soporte::sole()->pagina);
    }

    /**
     * El ticket nuevo por sí solo no cuenta que había otro abierto: la
     * decisión del orientador se registra aparte.
     */
    public function test_abrir_una_segunda_visita_queda_en_la_auditoria(): void
    {
        $abierto = $this->ticketDelPaciente();

        $this->conFormula($this->consultar())->call('generarDeTodosModos');

        $registro = Auditoria::where('descripcion', 'like', '%segunda visita%')->latest('id')->first();

        $this->assertNotNull($registro);
        $this->assertStringContainsString('1017234567', $registro->descripcion);
        $this->assertStringContainsString($abierto->turno, $registro->descripcion);

        // Del paciente solo su documento: nunca el nombre.
        $this->assertStringNotContainsString('MARIA', $registro->descripcion);
    }

    public function test_sin_visita_abierta_no_se_registra_nada_de_segunda_visita(): void
    {
        $this->conFormula($this->consultar())->call('generarTicket');

        $this->assertSame(0, Auditoria::where('descripcion', 'like', '%segunda visita%')->count());
    }

    /* ------------------------------------------------------------------ *
     *  El aviso desaparece cuando deja de ser cierto
     * ------------------------------------------------------------------ */

    public function test_si_la_visita_se_cierra_mientras_tanto_el_aviso_se_cae_solo(): void
    {
        $ticket = $this->ticketDelPaciente();

        $pagina = $this->consultar()->assertSet('visitaAbiertaId', $ticket->id);

        // Entrega atiende al paciente mientras el orientador llena el formulario.
        $ticket->update(['estado' => Ticket::ESTADO_ENTREGADO, 'cerrado_en' => now()]);

        // En el siguiente ida y vuelta con el servidor el aviso ya no se pinta:
        // el accesor vuelve a buscar en vez de confiar en el id del estado.
        $pagina->call('$refresh')->assertDontSee('Este paciente ya tiene una visita hoy');
    }

    public function test_atender_al_siguiente_limpia_el_aviso(): void
    {
        $this->ticketDelPaciente();

        $this->consultar()
            ->call('atenderOtro')
            ->assertSet('visitaAbiertaId', null)
            ->assertSet('visitaAbiertaMotivo', null);
    }
}
