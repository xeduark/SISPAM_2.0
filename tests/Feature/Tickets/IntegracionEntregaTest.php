<?php

namespace Tests\Feature\Tickets;

use App\Contracts\Ticket\TicketCierreInterface;
use App\Contracts\Ticket\TicketConsultaInterface;
use App\Filament\Pages\AtenderEntrega;
use App\Models\Cola;
use App\Models\Entrega;
use App\Models\EntregaItem;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Entrega\RegistrarEntrega;
use App\Services\Ticket\TicketConsultaDb;
use App\Services\Tickets\AlistarTicket;
use App\Services\Tickets\GenerarTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El módulo de entrega leyendo tickets reales, no el mock.
 */
class IntegracionEntregaTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private User $dispensador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sede = Sede::factory()->create(['nombre' => 'LA 30', 'codigo' => 'L30']);
        Cola::factory()->create([
            'sede_id' => $this->sede->id,
            'prefijo' => 'A',
            'orden' => 1,
            'atiende_alto_costo' => false,
        ]);

        $this->dispensador = User::factory()->administrador()->create(['sede_id' => $this->sede->id]);
        $this->actingAs($this->dispensador);
    }

    /** Un ticket real, alistado y listo para entrega. */
    private function ticketListo(?Paciente $paciente = null, ?Sede $sede = null): Ticket
    {
        $sede ??= $this->sede;

        $ticket = app(GenerarTicket::class)->handle(
            $paciente ?? Paciente::factory()->create(['tipo_documento' => 'CC', 'numero_documento' => '1017234567']),
            $sede,
            $this->dispensador,
        );

        return app(AlistarTicket::class)->handle($ticket, $this->dispensador, [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 20, 'unidad' => 'TAB'],
            ['codigo' => 'MED-002', 'nombre' => 'LOSARTAN 50 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
        ]);
    }

    /* ------------------------------------------------------------------ *
     *  El binding y el contrato
     * ------------------------------------------------------------------ */

    public function test_entrega_ya_consulta_los_tickets_reales_y_no_el_mock(): void
    {
        $this->assertInstanceOf(TicketConsultaDb::class, app(TicketConsultaInterface::class));
    }

    public function test_un_numero_que_no_existe_devuelve_null(): void
    {
        $this->assertNull(app(TicketConsultaInterface::class)->buscarPorNumero('NO-EXISTE'));
        $this->assertNull(app(TicketConsultaInterface::class)->buscarPorNumero('  '));
    }

    public function test_el_dto_trae_lo_que_entrega_necesita(): void
    {
        $paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
            'primer_nombre' => 'JUAN',
            'segundo_nombre' => null,
            'primer_apellido' => 'PEREZ',
            'segundo_apellido' => null,
            'telefono_movil' => '3001234567',
            'direccion' => 'KR 40 70A 23',
            'barrio' => 'MANRIQUE',
            'ciudad_residencia' => 'MEDELLÍN',
            'indicaciones_entrega' => 'Portería torre 2',
        ]);

        $ticket = $this->ticketListo($paciente);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        $this->assertNotNull($dto);
        $this->assertTrue($dto->listoParaEntrega());
        $this->assertSame($ticket->numero, $dto->numero);
        $this->assertSame($ticket->turno, $dto->turno);
        $this->assertSame($this->sede->id, $dto->sedeId);

        $this->assertSame($paciente->id, $dto->paciente->id);
        $this->assertSame('CC', $dto->paciente->tipoDocumento);
        $this->assertSame('JUAN PEREZ', $dto->paciente->nombreCompleto);
        $this->assertSame('3001234567', $dto->paciente->telefonoMovil);
        $this->assertSame('Portería torre 2', $dto->paciente->indicacionesEntrega);
        $this->assertTrue($dto->paciente->contactoConfirmado);

        $this->assertCount(2, $dto->items);
        $this->assertSame('MED-001', $dto->items[0]->codigo);
        $this->assertSame(20.0, $dto->items[0]->cantidad);
        $this->assertSame('TAB', $dto->items[0]->unidad);
    }

    public function test_un_ticket_sin_alistar_no_esta_listo_para_entrega(): void
    {
        $ticket = app(GenerarTicket::class)->handle(
            Paciente::factory()->create(),
            $this->sede,
            $this->dispensador,
        );

        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        $this->assertNotNull($dto);
        $this->assertFalse($dto->listoParaEntrega());
    }

    public function test_tambien_se_puede_buscar_por_el_turno_corto_del_dia(): void
    {
        $ticket = $this->ticketListo();

        // En el mostrador el paciente muestra «0001», no el número largo.
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->turno);

        $this->assertNotNull($dto);
        $this->assertSame($ticket->numero, $dto->numero);
    }

    public function test_el_turno_de_otro_dia_no_se_encuentra(): void
    {
        $ticket = $this->ticketListo();
        $ticket->update(['fecha' => today()->subDay()]);

        $this->assertNull(app(TicketConsultaInterface::class)->buscarPorNumero($ticket->turno));

        // Por número sí, porque el número lleva la fecha dentro.
        $this->assertNotNull(app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero));
    }

    /* ------------------------------------------------------------------ *
     *  La validación de sede
     * ------------------------------------------------------------------ */

    public function test_no_se_atiende_un_ticket_de_otra_sede(): void
    {
        $bic = Sede::factory()->create(['nombre' => 'EDIFICIO BIC', 'codigo' => 'BIC']);
        Cola::factory()->create(['sede_id' => $bic->id, 'prefijo' => 'A', 'atiende_alto_costo' => false]);

        $deOtraSede = $this->ticketListo(null, $bic);

        // Un dispensador de La 30, no administrador.
        $this->actingAs(User::factory()->create([
            'sede_id' => $this->sede->id,
            'roles' => ['DISPENSADOR'],
        ]));
        Rol::create(['nombre' => 'DISPENSADOR', 'permisos' => ['entrega' => ['ver', 'atender']]]);

        Livewire::test(AtenderEntrega::class)
            ->fillForm(['ticket_numero' => $deOtraSede->numero], 'busquedaForm')
            ->call('buscar')
            ->assertNotified('Ticket de otra sede')
            ->assertSet('ticket', null);
    }

    public function test_el_de_su_propia_sede_si_se_atiende(): void
    {
        $ticket = $this->ticketListo();

        Livewire::test(AtenderEntrega::class)
            ->fillForm(['ticket_numero' => $ticket->numero], 'busquedaForm')
            ->call('buscar')
            ->assertNotSet('ticket', null);
    }

    /* ------------------------------------------------------------------ *
     *  El cierre del ticket
     * ------------------------------------------------------------------ */

    public function test_una_entrega_completa_deja_el_ticket_entregado(): void
    {
        $ticket = $this->ticketListo();
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        app(RegistrarEntrega::class)->handle($dto, $this->dispensador, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => 'PACIENTE DE PRUEBA',
            'receptor_documento' => '1017234567',
            'firma_contenido' => 'firma-bytes',
            'items' => collect($dto->items)->map(fn ($item): array => [
                'ticket_item_id' => $item->id,
                'codigo' => $item->codigo,
                'nombre' => $item->nombre,
                'cantidad_solicitada' => $item->cantidad,
                'unidad' => $item->unidad,
                'cantidad_entregada' => $item->cantidad,
                'resultado' => EntregaItem::RESULTADO_ENTREGADO,
            ])->all(),
        ]);

        $ticket->refresh();

        $this->assertSame(Ticket::ESTADO_ENTREGADO, $ticket->estado);
        $this->assertNotNull($ticket->cerrado_en);
        $this->assertFalse($ticket->estaEntregable());
    }

    public function test_una_entrega_con_faltantes_deja_el_ticket_parcial(): void
    {
        $ticket = $this->ticketListo();
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        app(RegistrarEntrega::class)->handle($dto, $this->dispensador, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => 'PACIENTE DE PRUEBA',
            'receptor_documento' => '1017234567',
            'firma_contenido' => 'firma-bytes',
            'items' => collect($dto->items)->values()->map(fn ($item, int $i): array => [
                'ticket_item_id' => $item->id,
                'codigo' => $item->codigo,
                'nombre' => $item->nombre,
                'cantidad_solicitada' => $item->cantidad,
                'unidad' => $item->unidad,
                'cantidad_entregada' => $i === 0 ? 0 : $item->cantidad,
                'resultado' => $i === 0 ? EntregaItem::RESULTADO_FALTANTE : EntregaItem::RESULTADO_ENTREGADO,
                'motivo' => $i === 0 ? 'Sin stock en sede' : null,
            ])->all(),
        ]);

        $ticket->refresh();

        // Sigue entregable: al paciente le falta llevarse algo.
        $this->assertSame(Ticket::ESTADO_PARCIAL, $ticket->estado);
        $this->assertTrue($ticket->estaEntregable());
        $this->assertNull($ticket->cerrado_en);
    }

    public function test_cerrar_un_numero_que_no_existe_no_revienta(): void
    {
        // Nunca puede tumbar una entrega que ya se le hizo al paciente.
        app(TicketCierreInterface::class)->cerrarPorEntrega('NO-EXISTE', true);

        $this->assertTrue(true);
    }

    public function test_un_ticket_ya_anulado_no_se_reabre_por_una_entrega(): void
    {
        $ticket = $this->ticketListo();
        app(AlistarTicket::class)->anular($ticket, $this->dispensador, 'Orden duplicada');

        app(TicketCierreInterface::class)->cerrarPorEntrega($ticket->numero, true);

        $this->assertSame(Ticket::ESTADO_ANULADO, $ticket->fresh()->estado);
    }
}
