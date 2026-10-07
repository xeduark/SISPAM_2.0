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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La pantalla de Orientación: recibir al paciente y abrir su visita.
 *
 * Nunca toca el servicio real: Http::fake() responde en lugar de Savia.
 */
class OrientacionTest extends TestCase
{
    use RefreshDatabase;

    private const URL_TOKEN = '*rest/token/generacion';

    private const URL_CONSULTA = '*rest/afiliado/consultar-afiliado';

    private Sede $sede;

    private User $orientador;

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

        $this->actingAs($this->orientador);
    }

    /* ------------------------------------------------------------------ *
     *  Andamiaje
     * ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function respuestaSavia(array $extra = []): array
    {
        return [
            'registros' => 1,
            'codigo' => 0,
            'mensaje' => 'Afiliado encontrado',
            'fechaTransaccion' => '06/10/2026 10:00:00',
            'afiliados' => [array_merge([
                'tipoDocumentoAfiliado' => 'CC',
                'documentoAfiliado' => '1017234567',
                'primerNombreAfiliado' => 'MARIA',
                'segundoNombreAfiliado' => 'ELENA',
                'primerApellidoAfiliado' => 'GOMEZ',
                'segundoApellidoAfiliado' => 'RUIZ',
                'fechaNacimientoAfiliado' => '1990-05-14',
                'sexoAfiliado' => 'F',
                'estadoAfiliacion' => 'Activo',
                'regimen' => 'SUBSIDIADO',
                'municipioAfiliacion' => 'MEDELLIN',
                'departamentoAfiliacion' => 'ANTIOQUIA',
                'telefonoMovil' => '3001234567',
                'telefono' => '6043005012',
                'direccion' => 'KR 40 70A 23',
                'barrio' => 'MANRIQUE ORIENTAL',
                'descripcionCiudadResidencia' => 'MEDELLÍN',
                'discapacidad' => 'NO',
            ], $extra)],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function fakeConsultaExitosa(array $extra = []): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A', 'expires_in' => 3600]),
            self::URL_CONSULTA => Http::response($this->respuestaSavia($extra)),
        ]);
    }

    /** Abre la pantalla y consulta el documento. */
    private function consultar(string $numero = '1017234567'): Testable
    {
        return Livewire::test(Orientacion::class)
            ->fillForm(['tipo_documento' => 'CC', 'numero_documento' => $numero], 'formularioBusqueda')
            ->call('buscar');
    }

    /**
     * Llena lo que falta de la visita y pulsa «Generar ticket».
     *
     * El contacto ya viene precargado por la consulta; aquí solo se marca la
     * casilla de confirmación y se adjunta la fórmula.
     *
     * @param  array<string, mixed>  $extra
     */
    private function generar(Testable $pagina, array $extra = []): Testable
    {
        return $pagina
            ->fillForm(array_merge([
                'contacto_confirmado' => true,
                'orden_medica' => [UploadedFile::fake()->image('formula.jpg')],
            ], $extra), 'formularioVisita')
            ->call('generarTicket');
    }

    /* ------------------------------------------------------------------ *
     *  El camino feliz
     * ------------------------------------------------------------------ */

    public function test_una_visita_completa_crea_paciente_soporte_y_ticket(): void
    {
        $this->fakeConsultaExitosa();

        $this->generar($this->consultar())->assertHasNoFormErrors([], 'formularioVisita');

        $paciente = Paciente::firstWhere('numero_documento', '1017234567');
        $this->assertNotNull($paciente);
        $this->assertSame('MARIA', $paciente->primer_nombre);

        $ticket = Ticket::firstWhere('paciente_id', $paciente->id);
        $this->assertNotNull($ticket);
        $this->assertSame(Ticket::ESTADO_GENERADO, $ticket->estado);
        $this->assertSame($this->sede->id, $ticket->sede_id);
        $this->assertStringStartsWith('TK-PRP-', $ticket->numero);

        // El ticket nace sin medicamentos y sin marca de alto costo: eso lo
        // pone farmacia al alistar.
        $this->assertFalse((bool) $ticket->alto_costo);
        $this->assertCount(0, $ticket->items);

        // La fórmula queda colgada de la visita, en el disco privado.
        $soporte = Soporte::firstWhere('paciente_id', $paciente->id);
        $this->assertNotNull($soporte);
        $this->assertSame($ticket->id, $soporte->ticket_id);
        $this->assertSame($this->orientador->id, $soporte->cargado_por);
        Storage::disk('local')->assertExists($soporte->orden_medica);
    }

    public function test_el_contacto_confirmado_queda_sellado_con_quien_lo_confirmo(): void
    {
        $this->fakeConsultaExitosa();

        $this->generar($this->consultar(), [
            'telefono_movil' => '3109998877',
            'direccion' => 'CL 10 # 43-21 AP 302',
        ]);

        $paciente = Paciente::firstWhere('numero_documento', '1017234567');

        $this->assertSame('3109998877', $paciente->telefono_movil);
        $this->assertSame('CL 10 # 43-21 AP 302', $paciente->direccion);
        $this->assertNotNull($paciente->contacto_confirmado_at);
        $this->assertSame($this->orientador->id, $paciente->contacto_confirmado_por);
    }

    public function test_un_paciente_que_ya_existe_se_actualiza_y_no_se_duplica(): void
    {
        $existente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
            'primer_nombre' => 'NOMBRE VIEJO',
            'telefono_movil' => '3000000000',
        ]);

        $this->fakeConsultaExitosa();

        $this->generar($this->consultar());

        $this->assertSame(1, Paciente::where('numero_documento', '1017234567')->count());

        $existente->refresh();
        $this->assertSame('MARIA', $existente->primer_nombre);
    }

    /**
     * El contacto es de SISPAM, no de Savia: una consulta no puede pisarlo.
     */
    public function test_la_consulta_no_sobrescribe_el_contacto_que_ya_tenia_el_paciente(): void
    {
        $existente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
            'telefono_movil' => '3171112233',
            'direccion' => 'DIRECCION CONFIRMADA ANTES',
        ]);

        $this->fakeConsultaExitosa();

        // Se genera sin tocar los campos: lo precargado debe ser lo de SISPAM.
        $this->generar($this->consultar());

        $existente->refresh();
        $this->assertSame('3171112233', $existente->telefono_movil);
        $this->assertSame('DIRECCION CONFIRMADA ANTES', $existente->direccion);
    }

    /* ------------------------------------------------------------------ *
     *  Prioridad
     * ------------------------------------------------------------------ */

    public function test_sugiere_preferencial_por_edad_pero_el_orientador_decide(): void
    {
        $this->fakeConsultaExitosa(['fechaNacimientoAfiliado' => '1950-01-01']);

        $pagina = $this->consultar()
            ->assertFormSet([
                'prioridad' => Ticket::PRIORIDAD_PREFERENCIAL,
                'motivo_prioridad' => 'adulto_mayor',
            ], 'formularioVisita');

        // El orientador la baja a normal: su decisión manda sobre la sugerencia.
        $this->generar($pagina, ['prioridad' => Ticket::PRIORIDAD_NORMAL]);

        $ticket = Ticket::first();
        $this->assertSame(Ticket::PRIORIDAD_NORMAL, $ticket->prioridad);
        $this->assertNull($ticket->motivo_prioridad);
    }

    public function test_sugiere_preferencial_por_discapacidad(): void
    {
        $this->fakeConsultaExitosa(['discapacidad' => 'FISICA']);

        $this->consultar()->assertFormSet([
            'prioridad' => Ticket::PRIORIDAD_PREFERENCIAL,
            'motivo_prioridad' => 'discapacidad',
        ], 'formularioVisita');
    }

    public function test_un_afiliado_sin_condiciones_nace_normal(): void
    {
        $this->fakeConsultaExitosa();

        $this->generar($this->consultar());

        $this->assertSame(Ticket::PRIORIDAD_NORMAL, Ticket::first()->prioridad);
    }

    /* ------------------------------------------------------------------ *
     *  Lo que no deja pasar
     * ------------------------------------------------------------------ */

    public function test_sin_confirmar_el_contacto_no_se_genera_el_ticket(): void
    {
        $this->fakeConsultaExitosa();

        $this->consultar()
            ->fillForm([
                'contacto_confirmado' => false,
                'orden_medica' => [UploadedFile::fake()->image('formula.jpg')],
            ], 'formularioVisita')
            ->call('generarTicket')
            ->assertHasFormErrors(['contacto_confirmado'], 'formularioVisita');

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Paciente::count());
    }

    public function test_sin_formula_medica_no_se_genera_el_ticket(): void
    {
        $this->fakeConsultaExitosa();

        $this->consultar()
            ->fillForm(['contacto_confirmado' => true], 'formularioVisita')
            ->call('generarTicket')
            ->assertHasFormErrors(['orden_medica'], 'formularioVisita');

        $this->assertSame(0, Ticket::count());
    }

    /**
     * Ocultar el botón no impide una petición armada a mano.
     */
    public function test_sin_consulta_previa_no_se_abre_ninguna_visita(): void
    {
        Livewire::test(Orientacion::class)->call('generarTicket');

        $this->assertSame(0, Paciente::count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Soporte::count());
    }

    public function test_un_afiliado_que_savia_no_encuentra_no_deja_abrir_la_visita(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A', 'expires_in' => 3600]),
            self::URL_CONSULTA => Http::response([
                'registros' => 0,
                'codigo' => -1000,
                'mensaje' => 'Afiliado no encontrado',
                'afiliados' => [],
            ]),
        ]);

        $this->consultar('9999999999')->assertSet('atributos', null);

        $this->assertSame(0, Paciente::count());
        $this->assertSame(0, Ticket::count());
    }

    /* ------------------------------------------------------------------ *
     *  La sede sin configurar
     * ------------------------------------------------------------------ */

    /**
     * Perder la orientación por un problema de configuración sería peor que
     * quedarse sin turno: el paciente y su fórmula igual se guardan.
     */
    public function test_sin_colas_activas_el_paciente_y_la_formula_igual_se_guardan(): void
    {
        Cola::query()->update(['activa' => false]);

        $this->fakeConsultaExitosa();

        $this->generar($this->consultar());

        $paciente = Paciente::firstWhere('numero_documento', '1017234567');
        $this->assertNotNull($paciente);

        $soporte = Soporte::firstWhere('paciente_id', $paciente->id);
        $this->assertNotNull($soporte);
        $this->assertNull($soporte->ticket_id);

        $this->assertSame(0, Ticket::count());
    }

    /* ------------------------------------------------------------------ *
     *  Permisos y rastro
     * ------------------------------------------------------------------ */

    public function test_sin_el_permiso_de_orientacion_la_pantalla_no_se_abre(): void
    {
        Rol::create(['nombre' => 'CAJERO', 'permisos' => ['pacientes' => ['ver']]]);

        $this->actingAs(User::factory()->create([
            'roles' => ['CAJERO'],
            'sede_id' => $this->sede->id,
        ]));

        $this->assertFalse(Orientacion::canAccess());
        $this->get('/admin/orientacion')->assertForbidden();
    }

    public function test_el_orientador_entra_y_el_administrador_tambien(): void
    {
        $this->get('/admin/orientacion')->assertSuccessful();

        $this->actingAs(User::factory()->administrador()->create(['sede_id' => $this->sede->id]));
        $this->get('/admin/orientacion')->assertSuccessful();
    }

    /**
     * De la consulta solo queda el documento. Nunca los datos del afiliado:
     * son datos de salud.
     */
    public function test_la_consulta_deja_rastro_del_documento_y_de_nada_mas(): void
    {
        $this->fakeConsultaExitosa();

        $this->consultar();

        $auditoria = Auditoria::where('accion', Auditoria::ACCION_CONSULTO_SAVIA)->latest('id')->first();

        $this->assertNotNull($auditoria);
        $this->assertStringContainsString('1017234567', $auditoria->descripcion);
        $this->assertStringNotContainsString('MARIA', $auditoria->descripcion);
        $this->assertStringNotContainsString('GOMEZ', $auditoria->descripcion);
    }

    /* ------------------------------------------------------------------ *
     *  Después de generar
     * ------------------------------------------------------------------ */

    public function test_tras_generar_la_pantalla_muestra_el_turno_y_se_puede_atender_al_siguiente(): void
    {
        $this->fakeConsultaExitosa();

        $pagina = $this->generar($this->consultar());

        $ticket = Ticket::first();
        $pagina->assertSet('ticketGeneradoId', $ticket->id)
            ->assertSee($ticket->turno)
            ->assertSee($ticket->numero);

        // «Atender el que sigue» deja la pantalla en blanco para el próximo.
        $pagina->call('atenderOtro')
            ->assertSet('ticketGeneradoId', null)
            ->assertSet('atributos', null)
            ->assertSet('consultado', false);
    }
}
