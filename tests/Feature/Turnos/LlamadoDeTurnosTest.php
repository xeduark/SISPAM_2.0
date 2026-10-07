<?php

namespace Tests\Feature\Turnos;

use App\Filament\Pages\LlamarTurnos;
use App\Models\Cola;
use App\Models\Llamado;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Ventanilla;
use App\Services\Ticket\TicketCierreDb;
use App\Services\Ticket\TicketConsultaDb;
use App\Services\Turnos\LlamadorDeTurnos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Llamado de turnos (fase 6): a quién le toca, qué ve la sala y qué no.
 */
class LlamadoDeTurnosTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private Cola $general;

    private Cola $altoCosto;

    private Ventanilla $uno;

    private Ventanilla $dos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sede = Sede::factory()->create(['nombre' => 'La 30', 'codigo' => 'LA30']);

        $this->general = Cola::factory()->create([
            'sede_id' => $this->sede->id,
            'nombre' => 'Dispensación general',
            'prefijo' => 'A',
            'orden' => 1,
        ]);

        $this->altoCosto = Cola::factory()->create([
            'sede_id' => $this->sede->id,
            'nombre' => 'Alto costo',
            'prefijo' => 'B',
            'orden' => 2,
            'atiende_alto_costo' => true,
        ]);

        $this->uno = Ventanilla::factory()->create(['sede_id' => $this->sede->id, 'nombre' => 'Ventanilla 1', 'orden' => 1]);
        $this->dos = Ventanilla::factory()->create(['sede_id' => $this->sede->id, 'nombre' => 'Ventanilla 2', 'orden' => 2]);
    }

    private function llamador(): LlamadorDeTurnos
    {
        return app(LlamadorDeTurnos::class);
    }

    /** Un ticket alistado y esperando en la sala. */
    private function enEspera(array $atributos = []): Ticket
    {
        return Ticket::factory()->listo()->create(array_merge([
            'sede_id' => $this->sede->id,
            'cola_id' => $this->general->id,
        ], $atributos));
    }

    /** Quien atiende en la ventanilla. */
    private function dispensador(array $acciones = ['ver', 'llamar', 'ausente']): User
    {
        Rol::updateOrCreate(['nombre' => 'DISPENSADOR'], ['permisos' => ['turnos' => $acciones]]);

        return User::factory()->create(['sede_id' => $this->sede->id, 'roles' => ['DISPENSADOR']]);
    }

    /* ------------------------------------------------------------------ *
     *  A quién le toca
     * ------------------------------------------------------------------ */

    public function test_el_preferencial_va_primero_aunque_llegue_despues(): void
    {
        $normal = $this->enEspera(['turno' => 'A-001', 'created_at' => now()->subHour()]);
        $preferencial = Ticket::factory()->listo()->preferencial()->create([
            'sede_id' => $this->sede->id,
            'cola_id' => $this->general->id,
            'turno' => 'A-002',
            'created_at' => now(),
        ]);

        $llamado = $this->llamador()->siguiente($this->uno, $this->dispensador());

        $this->assertSame($preferencial->id, $llamado->id);

        // Y el normal sigue esperando su puesto, no lo perdió.
        $this->assertSame($normal->id, $this->llamador()->siguiente($this->dos, $this->dispensador())->id);
    }

    public function test_dentro_de_la_misma_prioridad_va_el_que_llego_primero(): void
    {
        $primero = $this->enEspera(['turno' => 'A-001', 'created_at' => now()->subMinutes(30)]);
        $this->enEspera(['turno' => 'A-002', 'created_at' => now()->subMinutes(10)]);

        $this->assertSame($primero->id, $this->llamador()->siguiente($this->uno, $this->dispensador())->id);
    }

    public function test_no_se_llama_lo_que_farmacia_no_ha_alistado(): void
    {
        Ticket::factory()->create([
            'sede_id' => $this->sede->id,
            'cola_id' => $this->general->id,
            'estado' => Ticket::ESTADO_GENERADO,
        ]);

        $this->assertNull($this->llamador()->siguiente($this->uno, $this->dispensador()));
    }

    public function test_no_se_llaman_turnos_de_otra_sede(): void
    {
        $otra = Sede::factory()->create(['nombre' => 'BIC', 'codigo' => 'BIC']);
        Ticket::factory()->listo()->create([
            'sede_id' => $otra->id,
            'cola_id' => Cola::factory()->create(['sede_id' => $otra->id])->id,
        ]);

        $this->assertNull($this->llamador()->siguiente($this->uno, $this->dispensador()));
    }

    public function test_no_se_llaman_turnos_de_otro_dia(): void
    {
        $this->enEspera(['fecha' => now()->subDay()->toDateString()]);

        $this->assertNull($this->llamador()->siguiente($this->uno, $this->dispensador()));
    }

    public function test_solo_se_llama_de_las_colas_escogidas(): void
    {
        $this->enEspera(['turno' => 'A-001', 'created_at' => now()->subHour()]);
        $deAltoCosto = $this->enEspera(['cola_id' => $this->altoCosto->id, 'turno' => 'B-001']);

        $llamado = $this->llamador()->siguiente($this->uno, $this->dispensador(), [$this->altoCosto->id]);

        $this->assertSame($deAltoCosto->id, $llamado->id);
    }

    public function test_dos_ventanillas_no_se_llevan_el_mismo_turno(): void
    {
        $this->enEspera(['turno' => 'A-001', 'created_at' => now()->subHour()]);
        $this->enEspera(['turno' => 'A-002']);

        $primero = $this->llamador()->siguiente($this->uno, $this->dispensador());
        $segundo = $this->llamador()->siguiente($this->dos, $this->dispensador());

        $this->assertNotSame($primero->id, $segundo->id);
        $this->assertNull($this->llamador()->siguiente($this->uno, $this->dispensador()));
    }

    public function test_el_llamado_queda_registrado_con_quien_llamo_y_desde_donde(): void
    {
        $ticket = $this->enEspera();
        $usuario = $this->dispensador();

        $this->llamador()->siguiente($this->uno, $usuario);

        $this->assertDatabaseHas('llamados', [
            'ticket_id' => $ticket->id,
            'sede_id' => $this->sede->id,
            'cola_id' => $this->general->id,
            'ventanilla_id' => $this->uno->id,
            'llamado_por' => $usuario->id,
            'intento' => 1,
        ]);

        $ticket->refresh();

        $this->assertSame(Ticket::SALA_LLAMADO, $ticket->estado_sala);
        $this->assertSame($this->uno->id, $ticket->ventanilla_id);
        $this->assertSame($usuario->id, $ticket->llamado_por);
        $this->assertNotNull($ticket->llamado_en);
    }

    public function test_volver_a_llamar_suma_un_intento_sin_duplicar_el_turno(): void
    {
        $ticket = $this->enEspera();
        $usuario = $this->dispensador();

        $this->llamador()->siguiente($this->uno, $usuario);
        $this->llamador()->llamar($ticket->refresh(), $this->dos, $usuario);

        $this->assertSame(2, Llamado::where('ticket_id', $ticket->id)->count());
        $this->assertSame([1, 2], Llamado::where('ticket_id', $ticket->id)->orderBy('id')->pluck('intento')->all());

        // El ticket apunta al último llamado.
        $this->assertSame($this->dos->id, $ticket->refresh()->ventanilla_id);
    }

    public function test_no_se_llama_un_turno_desde_la_ventanilla_de_otra_sede(): void
    {
        $otra = Sede::factory()->create(['nombre' => 'BIC', 'codigo' => 'BIC']);
        $ventanillaAjena = Ventanilla::factory()->create(['sede_id' => $otra->id]);

        $this->expectException(InvalidArgumentException::class);

        $this->llamador()->llamar($this->enEspera(), $ventanillaAjena, $this->dispensador());
    }

    /* ------------------------------------------------------------------ *
     *  Lo que el llamado no toca
     * ------------------------------------------------------------------ */

    public function test_llamar_no_cambia_el_estado_del_ticket_y_entrega_lo_sigue_atendiendo(): void
    {
        $ticket = $this->enEspera();

        $this->llamador()->siguiente($this->uno, $this->dispensador());

        $this->assertSame(Ticket::ESTADO_LISTO, $ticket->refresh()->estado);

        // Es la prueba de fondo: al paciente que acaban de llamar hay que
        // poder atenderlo. Si llamar moviera `estado`, entrega lo rechazaría.
        $dto = app(TicketConsultaDb::class)->buscarPorNumero($ticket->numero);

        $this->assertNotNull($dto);
        $this->assertTrue($dto->listoParaEntrega());
    }

    public function test_cuando_entrega_registra_la_atencion_el_turno_sale_de_la_sala(): void
    {
        $ticket = $this->enEspera();
        $this->llamador()->siguiente($this->uno, $this->dispensador());

        app(TicketCierreDb::class)->cerrarPorEntrega($ticket->numero, true);

        $ticket->refresh();

        $this->assertSame(Ticket::ESTADO_ENTREGADO, $ticket->estado);
        $this->assertSame(Ticket::SALA_ATENDIDO, $ticket->estado_sala);
        $this->assertSame(0, $this->llamador()->cuantosEsperan($this->sede->id));
    }

    public function test_una_entrega_parcial_tampoco_vuelve_a_la_espera(): void
    {
        $ticket = $this->enEspera();
        $this->llamador()->siguiente($this->uno, $this->dispensador());

        app(TicketCierreDb::class)->cerrarPorEntrega($ticket->numero, false);

        $ticket->refresh();

        // Sigue entregable por los faltantes, pero el paciente ya fue atendido.
        $this->assertSame(Ticket::ESTADO_PARCIAL, $ticket->estado);
        $this->assertTrue($ticket->estaEntregable());
        $this->assertSame(Ticket::SALA_ATENDIDO, $ticket->estado_sala);
        $this->assertNull($this->llamador()->siguiente($this->dos, $this->dispensador()));
    }

    /* ------------------------------------------------------------------ *
     *  El que no se presentó
     * ------------------------------------------------------------------ */

    public function test_el_que_no_se_presenta_sale_de_la_espera_y_se_puede_volver_a_llamar(): void
    {
        $ticket = $this->enEspera();
        $usuario = $this->dispensador();

        $this->llamador()->siguiente($this->uno, $usuario);
        $this->llamador()->marcarAusente($ticket->refresh());

        $this->assertSame(Ticket::SALA_AUSENTE, $ticket->refresh()->estado_sala);

        // «Llamar al siguiente» no lo vuelve a tomar solo.
        $this->assertNull($this->llamador()->siguiente($this->uno, $usuario));
        $this->assertSame([$ticket->id], $this->llamador()->ausentes($this->sede->id)->pluck('id')->all());

        // Pero si aparece, se vuelve a llamar.
        $this->llamador()->llamar($ticket->refresh(), $this->uno, $usuario);

        $this->assertSame(Ticket::SALA_LLAMADO, $ticket->refresh()->estado_sala);
    }

    public function test_no_se_marca_ausente_a_quien_no_se_ha_llamado(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->llamador()->marcarAusente($this->enEspera());
    }

    /* ------------------------------------------------------------------ *
     *  La pantalla de la ventanilla
     * ------------------------------------------------------------------ */

    public function test_la_pantalla_de_llamado_exige_el_permiso(): void
    {
        $sinPermiso = User::factory()->create(['sede_id' => $this->sede->id]);

        $this->actingAs($sinPermiso);
        $this->assertFalse(LlamarTurnos::canAccess());

        $this->actingAs($this->dispensador());
        $this->assertTrue(LlamarTurnos::canAccess());
    }

    public function test_desde_la_pantalla_se_llama_el_siguiente_turno(): void
    {
        $ticket = $this->enEspera();

        Livewire::actingAs($this->dispensador())
            ->test(LlamarTurnos::class)
            ->set('config.ventanilla_id', $this->uno->id)
            ->call('llamarSiguiente')
            ->assertHasNoErrors();

        $ticket->refresh();

        $this->assertSame(Ticket::SALA_LLAMADO, $ticket->estado_sala);
        $this->assertSame($this->uno->id, $ticket->ventanilla_id);
    }

    public function test_la_pantalla_arranca_con_la_ventanilla_de_la_sede(): void
    {
        Livewire::actingAs($this->dispensador())
            ->test(LlamarTurnos::class)
            ->assertSet('config.ventanilla_id', $this->uno->id);
    }

    public function test_la_pantalla_muestra_la_espera_los_ausentes_y_los_ultimos_llamados(): void
    {
        $usuario = $this->dispensador();

        $esperando = $this->enEspera(['turno' => 'A-010']);
        $ausente = $this->enEspera(['turno' => 'A-011']);

        $this->llamador()->llamar($ausente, $this->uno, $usuario);
        $this->llamador()->marcarAusente($ausente->refresh());

        Livewire::actingAs($usuario)
            ->test(LlamarTurnos::class)
            ->set('config.ventanilla_id', $this->uno->id)
            ->assertOk()
            ->assertSee($esperando->turno)
            ->assertSee('No se presentaron (1)')
            ->assertSee($ausente->turno);
    }

    public function test_sin_el_permiso_de_ausentes_el_turno_sigue_llamado(): void
    {
        $ticket = $this->enEspera();
        $usuario = $this->dispensador(['ver', 'llamar']);

        $this->llamador()->siguiente($this->uno, $usuario);

        Livewire::actingAs($usuario)
            ->test(LlamarTurnos::class)
            ->set('config.ventanilla_id', $this->uno->id)
            ->call('noSePresento', $ticket->id);

        $this->assertSame(Ticket::SALA_LLAMADO, $ticket->refresh()->estado_sala);
    }

    public function test_el_aviso_del_menu_cuenta_a_los_que_esperan(): void
    {
        $this->enEspera();
        $this->enEspera(['turno' => 'A-002']);

        $this->actingAs($this->dispensador());

        $this->assertSame('2', LlamarTurnos::getNavigationBadge());
    }

    /* ------------------------------------------------------------------ *
     *  La pantalla de la sala
     * ------------------------------------------------------------------ */

    public function test_la_sala_muestra_el_turno_y_la_ventanilla(): void
    {
        $ticket = $this->enEspera(['turno' => 'A-007']);
        $this->llamador()->siguiente($this->uno, $this->dispensador());

        $respuesta = $this->get(route('sala', ['sede' => 'LA30']));

        $respuesta->assertOk();
        $respuesta->assertSee($ticket->turno);
        $respuesta->assertSee('Ventanilla 1');
        $respuesta->assertSee('La 30');
    }

    public function test_la_sala_no_publica_nada_del_paciente(): void
    {
        $paciente = Paciente::factory()->create([
            'primer_nombre' => 'Zoraida',
            'primer_apellido' => 'Marulanda',
            'numero_documento' => '71234567',
        ]);

        $ticket = $this->enEspera(['paciente_id' => $paciente->id]);
        $this->llamador()->siguiente($this->uno, $this->dispensador());

        $respuesta = $this->get(route('sala', ['sede' => 'LA30']));

        $respuesta->assertSee($ticket->turno);
        // Un televisor sin sesión no muestra ni el nombre, ni el documento,
        // ni el número del ticket.
        $respuesta->assertDontSee('Zoraida');
        $respuesta->assertDontSee('Marulanda');
        $respuesta->assertDontSee('71234567');
        $respuesta->assertDontSee($ticket->numero);
    }

    public function test_la_sala_de_una_sede_no_muestra_los_turnos_de_la_otra(): void
    {
        $this->enEspera(['turno' => 'A-009']);
        $this->llamador()->siguiente($this->uno, $this->dispensador());

        $bic = Sede::factory()->create(['nombre' => 'BIC', 'codigo' => 'BIC']);
        Ventanilla::factory()->create(['sede_id' => $bic->id, 'nombre' => 'Ventanilla 1']);

        $respuesta = $this->get(route('sala', ['sede' => 'BIC']));

        $respuesta->assertOk();
        $respuesta->assertDontSee('A-009');
    }

    public function test_el_json_de_la_sala_trae_los_ultimos_llamados(): void
    {
        $primero = $this->enEspera(['turno' => 'A-001', 'created_at' => now()->subHour()]);
        $segundo = $this->enEspera(['turno' => 'A-002']);

        $usuario = $this->dispensador();
        $this->llamador()->siguiente($this->uno, $usuario);
        $this->llamador()->siguiente($this->dos, $usuario);

        $respuesta = $this->getJson(route('sala.turnos', ['sede' => 'LA30']));

        $respuesta->assertOk();
        // El último llamado va de primero: es el que sale grande en la pantalla.
        $respuesta->assertJsonPath('turnos.0.turno', $segundo->turno);
        $respuesta->assertJsonPath('turnos.0.ventanilla', 'Ventanilla 2');
        $respuesta->assertJsonPath('turnos.1.turno', $primero->turno);
        $respuesta->assertJsonCount(2, 'turnos');
    }

    public function test_la_sala_de_una_sede_inactiva_no_se_publica(): void
    {
        Sede::factory()->create(['nombre' => 'La 7', 'codigo' => 'LA7', 'activa' => false]);

        $this->get(route('sala', ['sede' => 'LA7']))->assertNotFound();
    }
}
