<?php

namespace Tests\Feature\Tickets;

use App\Filament\Resources\TicketResource;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Models\Auditoria;
use App\Models\Cola;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\AlistarTicket;
use App\Services\Tickets\GenerarTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El ticket de una visita: cómo nace, cómo se alista y quién lo ve.
 */
class TicketTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private Cola $general;

    private Cola $altoCosto;

    private User $orientador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sede = Sede::factory()->create(['nombre' => 'LA 30', 'codigo' => 'L30']);

        $this->general = Cola::factory()->create([
            'sede_id' => $this->sede->id,
            'nombre' => 'Dispensación general',
            'prefijo' => 'A',
            'orden' => 1,
            'atiende_alto_costo' => false,
        ]);

        $this->altoCosto = Cola::factory()->create([
            'sede_id' => $this->sede->id,
            'nombre' => 'Alto costo y oncológicos',
            'prefijo' => 'B',
            'orden' => 2,
            'atiende_alto_costo' => true,
        ]);

        $this->orientador = User::factory()->create(['sede_id' => $this->sede->id]);
    }

    private function generar(array $datos = [], ?Paciente $paciente = null): Ticket
    {
        return app(GenerarTicket::class)->handle(
            $paciente ?? Paciente::factory()->create(),
            $this->sede,
            $this->orientador,
            $datos,
        );
    }

    /** Usuario con permisos sobre tickets en la sede de prueba. */
    private function farmaceutico(array $acciones = ['ver', 'alistar', 'anular']): User
    {
        Rol::updateOrCreate(['nombre' => 'FARMACIA'], ['permisos' => ['tickets' => $acciones]]);

        return User::factory()->create(['sede_id' => $this->sede->id, 'roles' => ['FARMACIA']]);
    }

    /* ------------------------------------------------------------------ *
     *  Cómo nace el ticket
     * ------------------------------------------------------------------ */

    public function test_nace_en_generado_y_sin_medicamentos(): void
    {
        $ticket = $this->generar();

        $this->assertSame(Ticket::ESTADO_GENERADO, $ticket->estado);
        $this->assertSame(0, $ticket->items()->count());
        $this->assertSame($this->orientador->id, $ticket->creado_por);
        $this->assertTrue($ticket->fecha->isToday());
    }

    public function test_el_numero_lleva_sede_fecha_y_turno_y_es_unico(): void
    {
        $ticket = $this->generar();

        $esperado = 'TK-L30-'.now()->format('ymd').'-0001';

        $this->assertSame($esperado, $ticket->numero);
        $this->assertSame('0001', $ticket->turno);

        // El segundo no repite ni número ni turno.
        $segundo = $this->generar();
        $this->assertSame('TK-L30-'.now()->format('ymd').'-0002', $segundo->numero);
        $this->assertSame('0002', $segundo->turno);
    }

    public function test_el_turno_se_repite_entre_sedes_pero_el_numero_no(): void
    {
        $bic = Sede::factory()->create(['nombre' => 'EDIFICIO BIC', 'codigo' => 'BIC']);
        Cola::factory()->create(['sede_id' => $bic->id, 'prefijo' => 'A', 'atiende_alto_costo' => false]);

        $enLa30 = $this->generar();
        $enBic = app(GenerarTicket::class)->handle(
            Paciente::factory()->create(),
            $bic,
            User::factory()->create(['sede_id' => $bic->id]),
        );

        // El paciente ve el mismo turno en las dos sedes...
        $this->assertSame('0001', $enLa30->turno);
        $this->assertSame('0001', $enBic->turno);

        // ...pero el número que busca entrega es distinto.
        $this->assertNotSame($enLa30->numero, $enBic->numero);
        $this->assertStringContainsString('L30', $enLa30->numero);
        $this->assertStringContainsString('BIC', $enBic->numero);
    }

    /**
     * El alto costo ya no manda a otra cola: lo marca farmacia al alistar,
     * cuando el turno ya se entregó. Todos nacen en la general.
     */
    public function test_todos_nacen_en_la_cola_general_y_sin_marca_de_alto_costo(): void
    {
        $primero = $this->generar();
        $segundo = $this->generar();

        $this->assertSame($this->general->id, $primero->cola_id);
        $this->assertSame($this->general->id, $segundo->cola_id);

        $this->assertSame('0001', $primero->turno);
        $this->assertSame('0002', $segundo->turno);

        // La marca la pone farmacia: aquí todavía no se sabe.
        $this->assertFalse($primero->alto_costo);
    }

    /** Si solo queda activa la de alto costo, el paciente se atiende igual. */
    public function test_si_no_hay_cola_general_usa_la_que_haya(): void
    {
        $this->general->update(['activa' => false]);

        $ticket = $this->generar();

        $this->assertSame($this->altoCosto->id, $ticket->cola_id);
    }

    public function test_una_sede_sin_colas_activas_avisa_con_un_mensaje_claro(): void
    {
        $this->sede->colas()->update(['activa' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no tiene colas activas');

        $this->generar();
    }

    public function test_guarda_la_prioridad_preferencial_y_su_motivo(): void
    {
        $ticket = $this->generar([
            'prioridad' => Ticket::PRIORIDAD_PREFERENCIAL,
            'motivo_prioridad' => 'gestante',
        ]);

        $this->assertTrue($ticket->esPreferencial());
        $this->assertSame('gestante', $ticket->motivo_prioridad);
    }

    /* ------------------------------------------------------------------ *
     *  Alistamiento
     * ------------------------------------------------------------------ */

    public function test_farmacia_captura_los_medicamentos_y_lo_deja_listo(): void
    {
        $ticket = $this->generar();
        $farmaceutico = $this->farmaceutico();

        app(AlistarTicket::class)->handle($ticket, $farmaceutico, [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 20, 'unidad' => 'TAB'],
            ['codigo' => 'MED-002', 'nombre' => 'LOSARTAN 50 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
        ]);

        $ticket->refresh();

        $this->assertSame(Ticket::ESTADO_LISTO, $ticket->estado);
        $this->assertTrue($ticket->estaEntregable());
        $this->assertSame(2, $ticket->items()->count());
        $this->assertSame($farmaceutico->id, $ticket->alistado_por);
        $this->assertNotNull($ticket->alistado_en);
        $this->assertSame('ACETAMINOFEN 500 MG', $ticket->items()->orderBy('id')->first()->nombre);
    }

    /**
     * El alto costo lo marca farmacia, que es quien ve los medicamentos. Al
     * orientador no se le pregunta porque no está en condiciones de saberlo.
     */
    public function test_farmacia_marca_el_alto_costo_al_alistar(): void
    {
        $ticket = $this->generar();
        $farmaceutico = $this->farmaceutico();

        // Nace sin marca: en ese momento no se conocían los medicamentos.
        $this->assertFalse($ticket->alto_costo);

        app(AlistarTicket::class)->handle($ticket, $farmaceutico, [
            ['codigo' => 'MED-900', 'nombre' => 'IMATINIB 400 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
        ], altoCosto: true);

        $this->assertTrue($ticket->refresh()->alto_costo);
    }

    /** Es una etiqueta, no un enrutamiento: ni el turno ni la cola se mueven. */
    public function test_marcar_alto_costo_no_cambia_el_turno_ni_la_cola(): void
    {
        $ticket = $this->generar();
        $cola = $ticket->cola_id;
        $turno = $ticket->turno;

        app(AlistarTicket::class)->handle($ticket, $this->farmaceutico(), [
            ['codigo' => 'MED-900', 'nombre' => 'IMATINIB 400 MG', 'cantidad' => 30],
        ], altoCosto: true);

        $ticket->refresh();

        $this->assertSame($cola, $ticket->cola_id);
        $this->assertSame($turno, $ticket->turno);
    }

    /**
     * Alistar de nuevo vuelve a escribir la marca, igual que los medicamentos.
     *
     * Ojo: un ticket ya `listo` **no se puede volver a alistar**
     * (`sePuedeAlistar()` solo admite `generado` y `en_alistamiento`), así que
     * hoy corregir la marca exige devolverlo a `en_alistamiento` a mano. Es la
     * misma limitación que ya tenían los medicamentos.
     */
    public function test_la_marca_de_alto_costo_se_vuelve_a_escribir_al_realistar(): void
    {
        $ticket = $this->generar();
        $farmaceutico = $this->farmaceutico();
        $medicamentos = [['codigo' => 'MED-900', 'nombre' => 'IMATINIB 400 MG', 'cantidad' => 30]];

        app(AlistarTicket::class)->handle($ticket, $farmaceutico, $medicamentos, altoCosto: true);
        $this->assertTrue($ticket->refresh()->alto_costo);

        $ticket->update(['estado' => Ticket::ESTADO_EN_ALISTAMIENTO]);
        app(AlistarTicket::class)->handle($ticket, $farmaceutico, $medicamentos, altoCosto: false);
        $this->assertFalse($ticket->refresh()->alto_costo);
    }

    public function test_volver_a_alistar_reemplaza_los_medicamentos_sin_duplicar(): void
    {
        $ticket = $this->generar();
        $farmaceutico = $this->farmaceutico();

        app(AlistarTicket::class)->handle($ticket, $farmaceutico, [
            ['codigo' => 'MED-001', 'nombre' => 'PRIMERO', 'cantidad' => 1],
        ]);

        // Se corrige el alistamiento: queda solo lo último.
        $ticket->update(['estado' => Ticket::ESTADO_EN_ALISTAMIENTO]);
        app(AlistarTicket::class)->handle($ticket, $farmaceutico, [
            ['codigo' => 'MED-002', 'nombre' => 'SEGUNDO', 'cantidad' => 2],
        ]);

        $ticket->refresh();

        $this->assertSame(1, $ticket->items()->count());
        $this->assertSame('SEGUNDO', $ticket->items()->first()->nombre);
    }

    public function test_no_se_alista_sin_medicamentos(): void
    {
        $ticket = $this->generar();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('al menos un medicamento');

        app(AlistarTicket::class)->handle($ticket, $this->farmaceutico(), []);
    }

    public function test_no_se_alista_un_ticket_ya_entregado(): void
    {
        $ticket = $this->generar();
        $ticket->update(['estado' => Ticket::ESTADO_ENTREGADO]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ya no se puede alistar');

        app(AlistarTicket::class)->handle($ticket, $this->farmaceutico(), [
            ['codigo' => 'MED-001', 'nombre' => 'X', 'cantidad' => 1],
        ]);
    }

    /* ------------------------------------------------------------------ *
     *  Anulación
     * ------------------------------------------------------------------ */

    public function test_anular_cierra_el_ticket_con_su_motivo(): void
    {
        $ticket = $this->generar();

        app(AlistarTicket::class)->anular($ticket, $this->farmaceutico(), 'El paciente se retiró');

        $ticket->refresh();

        $this->assertSame(Ticket::ESTADO_ANULADO, $ticket->estado);
        $this->assertSame('El paciente se retiró', $ticket->motivo_anulacion);
        $this->assertNotNull($ticket->cerrado_en);
        $this->assertTrue($ticket->estaCerrado());
        $this->assertFalse($ticket->estaEntregable());
    }

    public function test_no_se_anula_dos_veces(): void
    {
        $ticket = $this->generar();
        $farmaceutico = $this->farmaceutico();

        app(AlistarTicket::class)->anular($ticket, $farmaceutico, 'Primera');

        $this->expectException(\InvalidArgumentException::class);

        app(AlistarTicket::class)->anular($ticket->fresh(), $farmaceutico, 'Segunda');
    }

    /* ------------------------------------------------------------------ *
     *  La pantalla
     * ------------------------------------------------------------------ */

    public function test_cada_quien_ve_solo_los_tickets_de_su_sede(): void
    {
        $delaSede = $this->generar();

        $bic = Sede::factory()->create(['nombre' => 'EDIFICIO BIC', 'codigo' => 'BIC']);
        $deOtraSede = Ticket::factory()->create(['sede_id' => $bic->id]);

        $this->actingAs($this->farmaceutico());

        Livewire::test(ListTickets::class)
            ->assertCanSeeTableRecords([$delaSede])
            ->assertCanNotSeeTableRecords([$deOtraSede]);
    }

    public function test_la_pestana_por_alistar_muestra_solo_los_pendientes(): void
    {
        $pendiente = $this->generar();
        $listo = $this->generar();
        app(AlistarTicket::class)->handle($listo, $this->farmaceutico(), [
            ['codigo' => 'MED-001', 'nombre' => 'X', 'cantidad' => 1],
        ]);

        $this->actingAs($this->farmaceutico());

        Livewire::test(ListTickets::class)
            ->assertCanSeeTableRecords([$pendiente])
            ->assertCanNotSeeTableRecords([$listo->fresh()]);
    }

    public function test_alistar_desde_la_pantalla_deja_el_ticket_listo(): void
    {
        $ticket = $this->generar();

        $this->actingAs($this->farmaceutico());

        Livewire::test(ListTickets::class)
            ->callTableAction('alistar', $ticket, data: [
                'items' => [
                    ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 20, 'unidad' => 'TAB'],
                ],
            ]);

        $ticket->refresh();

        $this->assertSame(Ticket::ESTADO_LISTO, $ticket->estado);
        $this->assertSame(1, $ticket->items()->count());
    }

    public function test_anular_desde_la_pantalla_pide_motivo(): void
    {
        $ticket = $this->generar();

        $this->actingAs($this->farmaceutico());

        Livewire::test(ListTickets::class)
            ->callTableAction('anular', $ticket, data: ['motivo' => 'Orden duplicada']);

        $this->assertSame(Ticket::ESTADO_ANULADO, $ticket->fresh()->estado);
        $this->assertSame('Orden duplicada', $ticket->fresh()->motivo_anulacion);
    }

    public function test_sin_permiso_de_alistar_la_accion_no_aparece(): void
    {
        $ticket = $this->generar();

        $this->actingAs($this->farmaceutico(['ver']));

        Livewire::test(ListTickets::class)
            ->assertTableActionHidden('alistar', $ticket)
            ->assertTableActionHidden('anular', $ticket);
    }

    public function test_sin_permiso_no_se_entra_a_la_pantalla(): void
    {
        $this->actingAs(User::factory()->create(['sede_id' => $this->sede->id]));

        $this->get('/admin/tickets')->assertForbidden();
    }

    public function test_el_ticket_no_se_crea_ni_se_edita_desde_la_pantalla(): void
    {
        $this->actingAs(User::factory()->administrador()->create());

        $this->assertFalse(TicketResource::canCreate());
        $this->get('/admin/tickets/create')->assertNotFound();
    }

    /* ------------------------------------------------------------------ *
     *  Auditoría
     * ------------------------------------------------------------------ */

    public function test_los_cambios_de_estado_quedan_en_la_auditoria(): void
    {
        $this->actingAs($this->farmaceutico());

        $ticket = $this->generar();
        Auditoria::query()->delete();

        app(AlistarTicket::class)->handle($ticket, auth()->user(), [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 20],
        ]);

        $registro = Auditoria::where('entidad_tipo', 'ticket')->firstOrFail();

        $this->assertSame("Actualizó el ticket {$ticket->numero}", $registro->descripcion);
        $this->assertSame(['generado', 'listo'], $registro->cambios['estado']);

        // Ni el nombre del medicamento ni el del paciente entran al rastro.
        $todo = json_encode($registro->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('ACETAMINOFEN', $todo);
    }
}
