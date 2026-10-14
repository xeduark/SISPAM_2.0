<?php

namespace Tests\Feature\Orientacion;

use App\Filament\Pages\Orientacion;
use App\Jobs\LeerFormula;
use App\Models\Cola;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Soporte;
use App\Models\Ticket;
use App\Models\Transcripcion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El paso de Orientación a Transcripción.
 *
 * Cuando el orientador genera el ticket, cada hoja de la fórmula tiene que
 * entrar sola a la cola de transcripción y quedar **colgada del ticket
 * correcto**: por ahí se arma la orden de entrega, y es lo que le dice a la
 * transcriptora a qué paciente de la fila pertenece lo que está revisando.
 *
 * El puente es `Soporte::created` en `AppServiceProvider`, así que lo que se
 * prueba aquí es que los caminos de Orientación lo disparen de verdad.
 */
class PuenteConTranscripcionTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private User $orientador;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake('local');

        // La lectura con IA no se toca: lo que importa es que el trabajo salga.
        Bus::fake([LeerFormula::class]);

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

    private function consultar(): Testable
    {
        return Livewire::test(Orientacion::class)
            ->fillForm(['tipo_documento' => 'CC', 'numero_documento' => '1017234567'], 'formularioBusqueda')
            ->call('buscar');
    }

    /**
     * @param  list<UploadedFile>|null  $archivos
     */
    private function generar(?array $archivos = null): Testable
    {
        return $this->consultar()
            ->fillForm([
                'contacto_confirmado' => true,
                'orden_medica' => $archivos ?? [UploadedFile::fake()->image('hoja.jpg')],
            ], 'formularioVisita')
            ->call('generarTicket');
    }

    /* ------------------------------------------------------------------ *
     *  El camino normal
     * ------------------------------------------------------------------ */

    public function test_generar_el_ticket_manda_la_formula_a_transcripcion(): void
    {
        $this->colaActiva();

        $this->generar();

        $transcripcion = Transcripcion::sole();
        $ticket = Ticket::sole();

        $this->assertSame(Soporte::sole()->id, $transcripcion->soporte_id);
        $this->assertSame($ticket->id, $transcripcion->ticket_id);
        $this->assertSame($ticket->paciente_id, $transcripcion->paciente_id);
        $this->assertSame($this->sede->id, $transcripcion->sede_id);
        $this->assertSame(Transcripcion::ESTADO_EN_COLA, $transcripcion->estado);

        Bus::assertDispatched(LeerFormula::class);
    }

    /**
     * Cada hoja se transcribe por separado: una fórmula por imagen, y nunca
     * se mezclan los medicamentos de dos.
     */
    public function test_cada_hoja_entra_a_la_cola_por_separado(): void
    {
        $this->colaActiva();

        $this->generar([
            UploadedFile::fake()->image('hoja-1.jpg'),
            UploadedFile::fake()->image('hoja-2.jpg'),
            UploadedFile::fake()->image('hoja-3.jpg'),
        ]);

        $this->assertSame(3, Transcripcion::count());

        // Las tres cuelgan del mismo ticket y de hojas distintas.
        $ticket = Ticket::sole();
        $this->assertSame([$ticket->id], Transcripcion::pluck('ticket_id')->unique()->all());
        $this->assertCount(3, Transcripcion::pluck('soporte_id')->unique());

        Bus::assertDispatchedTimes(LeerFormula::class, 3);
    }

    public function test_sumar_hojas_a_una_visita_abierta_tambien_las_manda(): void
    {
        $this->colaActiva();

        $this->generar();
        $ticket = Ticket::sole();

        // El paciente vuelve con otra hoja de la misma fórmula.
        $this->consultar()
            ->fillForm([
                'contacto_confirmado' => true,
                'orden_medica' => [UploadedFile::fake()->image('hoja-2.jpg')],
            ], 'formularioVisita')
            ->call('sumarAVisitaAbierta');

        $this->assertSame(2, Transcripcion::count());
        $this->assertSame([$ticket->id], Transcripcion::pluck('ticket_id')->unique()->all());
    }

    /* ------------------------------------------------------------------ *
     *  La fórmula que quedó sin turno
     * ------------------------------------------------------------------ */

    /**
     * Sin colas no hay ticket, pero la fórmula igual se lee: la transcriptora
     * puede ir adelantando mientras alguien arregla la configuración.
     */
    public function test_sin_colas_la_formula_igual_entra_a_la_cola(): void
    {
        $this->generar();

        $transcripcion = Transcripcion::sole();

        $this->assertNull($transcripcion->ticket_id);
        // La sede sale de quien la cargó, para que no quede fuera de su bandeja.
        $this->assertSame($this->sede->id, $transcripcion->sede_id);
    }

    /**
     * Al completar la fórmula sin turno, la transcripción que ya existía tiene
     * que quedar colgada del ticket nuevo.
     *
     * La orden de entrega y la pantalla de revisión son defensivas y caen de
     * vuelta al ticket del soporte, así que la fórmula llega igual a farmacia.
     * Lo que se rompe es la bandeja de transcripción: muestra la columna del
     * turno vacía, y quien revisa no sabe a qué paciente de la fila pertenece.
     */
    public function test_al_completar_el_turno_la_transcripcion_queda_colgada_del_ticket(): void
    {
        // 1. La sede no tenía colas: la fórmula se guarda sin ticket.
        $this->generar();

        $transcripcion = Transcripcion::sole();
        $this->assertNull($transcripcion->ticket_id);

        // 2. Se arregla la configuración.
        $this->colaActiva();

        // 3. Se completa el turno desde Orientación.
        $this->consultar()->call('completarFormulaSinTurno');

        $ticket = Ticket::sole();

        $this->assertSame($ticket->id, $transcripcion->fresh()->ticket_id);
        $this->assertSame($this->sede->id, $transcripcion->fresh()->sede_id);

        // Y no se duplicó: es la misma lectura, no una nueva.
        $this->assertSame(1, Transcripcion::count());
    }
}
