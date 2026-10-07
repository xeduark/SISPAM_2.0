<?php

namespace Tests\Feature\Tickets;

use App\Contracts\Ticket\Dto\TicketDto;
use App\Contracts\Ticket\TicketCierreInterface;
use App\Contracts\Ticket\TicketConsultaInterface;
use App\Filament\Pages\AtenderEntrega;
use App\Models\Cola;
use App\Models\DomicilioEnvio;
use App\Models\Entrega;
use App\Models\EntregaItem;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Entrega\RegistrarEntrega;
use App\Services\Entrega\SaldoTicket;
use App\Services\Ticket\TicketConsultaDb;
use App\Services\Tickets\AlistarTicket;
use App\Services\Tickets\GenerarTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

        // Sin esto las firmas de prueba se escribían en storage/app/private real y pisaban
        // las firmas de las entregas locales con el mismo id.
        Storage::fake('local');

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

    /**
     * Un ticket real, alistado y listo para entrega.
     *
     * @param  list<array{codigo: string, nombre: string, cantidad: float|int, unidad?: string}>|null  $items
     */
    private function ticketListo(?Paciente $paciente = null, ?Sede $sede = null, ?array $items = null): Ticket
    {
        $sede ??= $this->sede;

        $ticket = app(GenerarTicket::class)->handle(
            $paciente ?? Paciente::factory()->create(['tipo_documento' => 'CC', 'numero_documento' => '1017234567']),
            $sede,
            $this->dispensador,
        );

        return app(AlistarTicket::class)->handle($ticket, $this->dispensador, $items ?? [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 20, 'unidad' => 'TAB'],
            ['codigo' => 'MED-002', 'nombre' => 'LOSARTAN 50 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
        ]);
    }

    /**
     * @param  list<array{ticket_item_id: string, codigo: string, nombre: string, cantidad_solicitada: float|int, unidad: string, cantidad_entregada: float|int, resultado?: string, motivo?: ?string}>  $items
     */
    private function registrarPresencial(string $numeroTicket, array $items, ?User $usuario = null, ?int $sedeId = null): Entrega
    {
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($numeroTicket);
        $this->assertNotNull($dto);

        return app(RegistrarEntrega::class)->handle($dto, $usuario ?? $this->dispensador, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'sede_id' => $sedeId ?? $this->sede->id,
            'receptor_nombre' => 'Receptor Prueba',
            'receptor_documento' => '100200300',
            'receptor_parentesco' => 'Paciente',
            'firma_contenido' => 'firma-bytes',
            'items' => $items,
        ]);
    }

    /**
     * @param  array<string, float>  $entregadasPorCodigo  codigo => cantidad_entregada
     * @return list<array<string, mixed>>
     */
    private function itemsDesdeDto(TicketDto $dto, array $entregadasPorCodigo, array $motivos = []): array
    {
        return collect($dto->items)->map(function ($item) use ($entregadasPorCodigo, $motivos): array {
            $entregada = (float) ($entregadasPorCodigo[$item->codigo] ?? 0);
            $fila = [
                'ticket_item_id' => $item->id,
                'codigo' => $item->codigo,
                'nombre' => $item->nombre,
                'cantidad_solicitada' => $item->cantidad,
                'unidad' => $item->unidad,
                'cantidad_entregada' => $entregada,
                'resultado' => $entregada <= 0
                    ? EntregaItem::RESULTADO_FALTANTE
                    : ($entregada < $item->cantidad ? EntregaItem::RESULTADO_PARCIAL : EntregaItem::RESULTADO_ENTREGADO),
            ];

            if ($entregada < $item->cantidad) {
                $fila['motivo'] = $motivos[$item->codigo] ?? 'Sin stock completo en sede';
            }

            return $fila;
        })->all();
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

    public function test_ticket_y_entrega_en_la_misma_sede_estan_permitidos(): void
    {
        $ticket = $this->ticketListo(items: [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 10, 'unidad' => 'TAB'],
        ]);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        $entrega = $this->registrarPresencial(
            $ticket->numero,
            $this->itemsDesdeDto($dto, ['MED-001' => 10]),
            sedeId: $ticket->sede_id,
        );

        $this->assertSame($ticket->sede_id, $entrega->sede_id);
        $this->assertDatabaseCount('entregas', 1);
    }

    public function test_ticket_de_centro_no_se_puede_entregar_en_norte(): void
    {
        $centro = Sede::factory()->create(['nombre' => 'Sede Centro', 'codigo' => 'CEN', 'activa' => true]);
        Cola::factory()->create(['sede_id' => $centro->id, 'prefijo' => 'A', 'atiende_alto_costo' => false]);

        $norte = Sede::factory()->create(['nombre' => 'Sede Norte', 'codigo' => 'NOR', 'activa' => true]);

        $ticket = $this->ticketListo(sede: $centro, items: [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 10, 'unidad' => 'TAB'],
        ]);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        try {
            $this->registrarPresencial(
                $ticket->numero,
                $this->itemsDesdeDto($dto, ['MED-001' => 10]),
                sedeId: $norte->id,
            );
            $this->fail('Debió rechazar la entrega en sede distinta a la del ticket.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Sede Centro', $e->getMessage());
            $this->assertStringContainsString('Este ticket pertenece a la', $e->getMessage());
            $this->assertStringContainsString('debe ser atendido en esa sede', $e->getMessage());
        }

        $this->assertDatabaseCount('entregas', 0);
    }

    public function test_dispensador_con_perfil_en_otra_sede_puede_atender_si_el_ticket_es_de_centro(): void
    {
        $centro = Sede::factory()->create(['nombre' => 'Sede Centro', 'codigo' => 'CEN2', 'activa' => true]);
        Cola::factory()->create(['sede_id' => $centro->id, 'prefijo' => 'A', 'atiende_alto_costo' => false]);
        $norte = Sede::factory()->create(['nombre' => 'Sede Norte', 'codigo' => 'NOR2', 'activa' => true]);

        // Perfil habitual en Norte, pero el ticket (y la entrega) son de Centro.
        $dispensador = User::factory()->create([
            'sede_id' => $norte->id,
            'roles' => ['DISPENSADOR'],
            'es_administrador' => false,
        ]);
        Rol::create(['nombre' => 'DISPENSADOR', 'permisos' => ['entrega' => ['ver', 'atender']]]);

        $ticket = $this->ticketListo(sede: $centro, items: [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 5, 'unidad' => 'TAB'],
        ]);

        $this->actingAs($dispensador);

        Livewire::test(AtenderEntrega::class)
            ->fillForm(['ticket_numero' => $ticket->numero], 'busquedaForm')
            ->call('buscar')
            ->assertNotSet('ticket', null)
            ->assertSet('atencion.sede_id', $centro->id);

        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);
        $entrega = $this->registrarPresencial(
            $ticket->numero,
            $this->itemsDesdeDto($dto, ['MED-001' => 5]),
            usuario: $dispensador,
            sedeId: $centro->id,
        );

        $this->assertSame($centro->id, $entrega->sede_id);
        $this->assertNotSame($norte->id, $entrega->sede_id);
        $this->assertSame($norte->id, $dispensador->fresh()->sede_id);
    }

    public function test_entrega_historica_conserva_sede_del_ticket_si_usuario_cambia_de_sede(): void
    {
        $centro = Sede::factory()->create(['nombre' => 'Sede Centro', 'codigo' => 'CEN3', 'activa' => true]);
        Cola::factory()->create(['sede_id' => $centro->id, 'prefijo' => 'A', 'atiende_alto_costo' => false]);
        $norte = Sede::factory()->create(['nombre' => 'Sede Norte', 'codigo' => 'NOR3', 'activa' => true]);

        $usuario = User::factory()->administrador()->create(['sede_id' => $centro->id]);
        $ticket = $this->ticketListo(sede: $centro, items: [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 5, 'unidad' => 'TAB'],
        ]);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        $entrega = $this->registrarPresencial(
            $ticket->numero,
            $this->itemsDesdeDto($dto, ['MED-001' => 5]),
            usuario: $usuario,
            sedeId: $centro->id,
        );

        $usuario->update(['sede_id' => $norte->id]);
        $entrega->refresh()->load('sede');

        $this->assertSame($centro->id, $entrega->sede_id);
        $this->assertSame('Sede Centro', $entrega->sede->nombre);
        $this->assertSame($norte->id, $usuario->fresh()->sede_id);
    }

    public function test_ui_fija_sede_del_ticket_y_no_la_del_usuario(): void
    {
        $ticket = $this->ticketListo();

        Livewire::test(AtenderEntrega::class)
            ->fillForm(['ticket_numero' => $ticket->numero], 'busquedaForm')
            ->call('buscar')
            ->assertSet('atencion.sede_id', $ticket->sede_id)
            ->assertSee('no se puede cambiar');
    }

    /* ------------------------------------------------------------------ *
     *  El cierre del ticket
     * ------------------------------------------------------------------ */

    public function test_escenario_a_entrega_completa_cierra_y_bloquea_el_ticket(): void
    {
        $ticket = $this->ticketListo(items: [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
            ['codigo' => 'MED-002', 'nombre' => 'LOSARTAN 50 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
        ]);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        $entrega = $this->registrarPresencial($ticket->numero, $this->itemsDesdeDto($dto, [
            'MED-001' => 30,
            'MED-002' => 30,
        ]));

        $this->assertSame(Entrega::ESTADO_COMPLETADA, $entrega->estado);
        $this->assertSame(0.0, (float) $entrega->items->sum('cantidad_pendiente'));

        $ticket->refresh();
        $this->assertSame(Ticket::ESTADO_ENTREGADO, $ticket->estado);
        $this->assertNotNull($ticket->cerrado_en);
        $this->assertFalse($ticket->estaEntregable());
        $this->assertSame(Ticket::SALA_ATENDIDO, $ticket->estado_sala);

        $dtoTras = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);
        $this->assertFalse($dtoTras->listoParaEntrega());
        $this->assertTrue(app(SaldoTicket::class)->ticketCompletamenteDispensado($dtoTras));

        // Con Ticket real el estado pasa a `entregado`: se muestra bloqueado, no «Sin ticket».
        Livewire::test(AtenderEntrega::class)
            ->fillForm(['ticket_numero' => $ticket->numero], 'busquedaForm')
            ->call('buscar')
            ->assertNotified('Ticket ya dispensado')
            ->assertSet('ticketBloqueado', true)
            ->assertSet('ticket.numero', $ticket->numero)
            ->assertSee('Dispensación bloqueada')
            ->assertDontSee('No se encontró un ticket disponible');
    }

    public function test_escenario_b_entrega_parcial_deja_pendientes_y_ticket_abierto(): void
    {
        $ticket = $this->ticketListo(items: [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
            ['codigo' => 'MED-002', 'nombre' => 'LOSARTAN 50 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
            ['codigo' => 'MED-003', 'nombre' => 'METFORMINA 850 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
        ]);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        $entrega = $this->registrarPresencial($ticket->numero, $this->itemsDesdeDto($dto, [
            'MED-001' => 30,
            'MED-002' => 15,
            'MED-003' => 0,
        ]));

        $porCodigo = $entrega->items->keyBy('codigo');
        $this->assertSame(0.0, (float) $porCodigo['MED-001']->cantidad_pendiente);
        $this->assertSame(15.0, (float) $porCodigo['MED-002']->cantidad_pendiente);
        $this->assertSame(30.0, (float) $porCodigo['MED-003']->cantidad_pendiente);
        $this->assertSame(Entrega::ESTADO_PARCIAL, $entrega->estado);

        $ticket->refresh();
        $this->assertSame(Ticket::ESTADO_PARCIAL, $ticket->estado);
        $this->assertTrue($ticket->estaEntregable());
        $this->assertNull($ticket->cerrado_en);

        $dtoTras = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);
        $pendientes = collect(app(SaldoTicket::class)->lineasPendientes($dtoTras))
            ->mapWithKeys(fn (array $l) => [$l['item']->codigo => $l['pendiente']]);

        $this->assertSame(['MED-002' => 15.0, 'MED-003' => 30.0], $pendientes->all());

        $componente = Livewire::test(AtenderEntrega::class)
            ->fillForm(['ticket_numero' => $ticket->numero], 'busquedaForm')
            ->call('buscar')
            ->assertSet('ticketBloqueado', false)
            ->assertNotSet('ticket', null);

        $itemsUi = collect($componente->get('atencion')['items'] ?? [])
            ->mapWithKeys(fn (array $i) => [$i['codigo'] => (float) $i['cantidad_solicitada']]);

        $this->assertSame(['MED-002' => 15.0, 'MED-003' => 30.0], $itemsUi->all());
    }

    public function test_escenario_c_segunda_atencion_completa_pendientes_y_bloquea(): void
    {
        $ticket = $this->ticketListo(items: [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
            ['codigo' => 'MED-002', 'nombre' => 'LOSARTAN 50 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
            ['codigo' => 'MED-003', 'nombre' => 'METFORMINA 850 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
        ]);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        $this->registrarPresencial($ticket->numero, $this->itemsDesdeDto($dto, [
            'MED-001' => 30,
            'MED-002' => 15,
            'MED-003' => 0,
        ]));

        $dtoParcial = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);
        $pendientes = app(SaldoTicket::class)->lineasPendientes($dtoParcial);

        $segunda = $this->registrarPresencial($ticket->numero, collect($pendientes)->map(fn (array $linea): array => [
            'ticket_item_id' => $linea['item']->id,
            'codigo' => $linea['item']->codigo,
            'nombre' => $linea['item']->nombre,
            'cantidad_solicitada' => $linea['pendiente'],
            'unidad' => $linea['item']->unidad,
            'cantidad_entregada' => $linea['pendiente'],
            'resultado' => EntregaItem::RESULTADO_ENTREGADO,
        ])->all());

        $this->assertSame(Entrega::ESTADO_COMPLETADA, $segunda->estado);
        $this->assertSame(0.0, (float) $segunda->items->sum('cantidad_pendiente'));

        $ticket->refresh();
        $this->assertSame(Ticket::ESTADO_ENTREGADO, $ticket->estado);
        $this->assertNotNull($ticket->cerrado_en);

        $dtoFinal = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);
        $this->assertTrue(app(SaldoTicket::class)->ticketCompletamenteDispensado($dtoFinal));
        $this->assertFalse($dtoFinal->listoParaEntrega());

        Livewire::test(AtenderEntrega::class)
            ->fillForm(['ticket_numero' => $ticket->numero], 'busquedaForm')
            ->call('buscar')
            ->assertNotified('Ticket ya dispensado')
            ->assertSet('ticketBloqueado', true)
            ->assertSet('ticket.numero', $ticket->numero)
            ->assertSee('Dispensación bloqueada')
            ->assertDontSee('No se encontró un ticket disponible');
    }

    public function test_no_permite_entregar_mas_del_saldo_del_ticket_real(): void
    {
        $ticket = $this->ticketListo(items: [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
        ]);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        $this->registrarPresencial($ticket->numero, [[
            'ticket_item_id' => $dto->items[0]->id,
            'codigo' => 'MED-001',
            'nombre' => 'ACETAMINOFEN 500 MG',
            'cantidad_solicitada' => 30,
            'unidad' => 'TAB',
            'cantidad_entregada' => 20,
            'resultado' => EntregaItem::RESULTADO_PARCIAL,
            'motivo' => 'Parcial de prueba',
        ]]);

        $this->expectException(\InvalidArgumentException::class);
        // Tras recalcular el saldo, solicitada se fija al pendiente (10); entregar 15
        // choca con esa cota antes de sobredispensar el ticket.
        $this->expectExceptionMessage('no se puede entregar más de lo solicitado');

        $this->registrarPresencial($ticket->numero, [[
            'ticket_item_id' => $dto->items[0]->id,
            'codigo' => 'MED-001',
            'nombre' => 'ACETAMINOFEN 500 MG',
            'cantidad_solicitada' => 10,
            'unidad' => 'TAB',
            'cantidad_entregada' => 15,
            'resultado' => EntregaItem::RESULTADO_ENTREGADO,
        ]]);
    }

    public function test_administrador_no_puede_forzar_sede_distinta_a_la_del_ticket(): void
    {
        $sedeTicket = $this->sede;
        $sedeOtra = Sede::factory()->create(['nombre' => 'BIC', 'codigo' => 'BIC', 'activa' => true]);

        $ticket = $this->ticketListo(sede: $sedeTicket, items: [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 10, 'unidad' => 'TAB'],
        ]);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('debe ser atendido en esa sede');

        $this->registrarPresencial(
            $ticket->numero,
            $this->itemsDesdeDto($dto, ['MED-001' => 10]),
            sedeId: $sedeOtra->id,
        );
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

    public function test_mensaje_claro_cuando_el_ticket_esta_anulado(): void
    {
        $ticket = $this->ticketListo();
        app(AlistarTicket::class)->anular($ticket, $this->dispensador, 'Duplicado');

        Livewire::test(AtenderEntrega::class)
            ->fillForm(['ticket_numero' => $ticket->numero], 'busquedaForm')
            ->call('buscar')
            ->assertNotified('Ticket anulado')
            ->assertSet('ticketBloqueado', true)
            ->assertSet('ticket.numero', $ticket->numero)
            ->assertSee('Dispensación bloqueada')
            ->assertDontSee('No se encontró un ticket disponible');
    }

    public function test_mensaje_claro_cuando_el_ticket_esta_vencido(): void
    {
        $ticket = $this->ticketListo();
        $ticket->update(['estado' => Ticket::ESTADO_VENCIDO]);

        Livewire::test(AtenderEntrega::class)
            ->fillForm(['ticket_numero' => $ticket->numero], 'busquedaForm')
            ->call('buscar')
            ->assertNotified('Ticket vencido')
            ->assertSet('ticketBloqueado', true)
            ->assertSet('ticket.numero', $ticket->numero)
            ->assertSee('Dispensación bloqueada')
            ->assertDontSee('No se encontró un ticket disponible');
    }

    /* ------------------------------------------------------------------ *
     *  Domicilio: logística independiente de la dispensación
     * ------------------------------------------------------------------ */

    private function avanzarDomicilioHastaEntregado(DomicilioEnvio $envio): DomicilioEnvio
    {
        $registrar = app(RegistrarEntrega::class);

        $envio = $registrar->cambiarEstadoDomicilio($envio, DomicilioEnvio::ESTADO_PREPARADO, $this->dispensador);
        $envio = $registrar->cambiarEstadoDomicilio($envio, DomicilioEnvio::ESTADO_ENVIADO_DOMINA, $this->dispensador);
        $envio = $registrar->cambiarEstadoDomicilio($envio, DomicilioEnvio::ESTADO_EN_RUTA, $this->dispensador);

        return $registrar->cambiarEstadoDomicilio($envio, DomicilioEnvio::ESTADO_ENTREGADO, $this->dispensador);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function registrarDomicilio(string $numeroTicket, array $items): Entrega
    {
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($numeroTicket);
        $this->assertNotNull($dto);

        return app(RegistrarEntrega::class)->handle($dto, $this->dispensador, [
            'tipo' => Entrega::TIPO_DOMICILIO,
            'sede_id' => $dto->sedeId,
            'items' => $items,
        ]);
    }

    public function test_d1_domicilio_completo_cierra_ticket_al_entregar_paquete(): void
    {
        $ticket = $this->ticketListo(items: [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
            ['codigo' => 'MED-002', 'nombre' => 'LOSARTAN 50 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
        ]);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        $entrega = $this->registrarDomicilio($ticket->numero, $this->itemsDesdeDto($dto, [
            'MED-001' => 30,
            'MED-002' => 30,
        ]));

        $envio = $this->avanzarDomicilioHastaEntregado($entrega->domicilioEnvio->fresh());

        $this->assertSame(DomicilioEnvio::ESTADO_ENTREGADO, $envio->estado);
        $this->assertSame(Entrega::ESTADO_COMPLETADA, $envio->entrega->fresh()->estado);

        $ticket->refresh();
        $this->assertSame(Ticket::ESTADO_ENTREGADO, $ticket->estado);
        $this->assertNotNull($ticket->cerrado_en);
        $this->assertTrue(app(SaldoTicket::class)->ticketCompletamenteDispensado(
            app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero)
        ));

        Livewire::test(AtenderEntrega::class)
            ->fillForm(['ticket_numero' => $ticket->numero], 'busquedaForm')
            ->call('buscar')
            ->assertNotified('Ticket ya dispensado')
            ->assertSet('ticketBloqueado', true)
            ->assertSet('ticket.numero', $ticket->numero)
            ->assertSee('Dispensación bloqueada')
            ->assertDontSee('No se encontró un ticket disponible');
    }

    public function test_d2_domicilio_parcial_no_cierra_ticket_aunque_el_paquete_llegue(): void
    {
        $ticket = $this->ticketListo(items: [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
            ['codigo' => 'MED-002', 'nombre' => 'LOSARTAN 50 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
        ]);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        $entrega = $this->registrarDomicilio($ticket->numero, $this->itemsDesdeDto($dto, [
            'MED-001' => 30,
            'MED-002' => 15,
        ]));

        $this->assertSame(Entrega::ESTADO_PARCIAL, $entrega->estado);

        $envio = $this->avanzarDomicilioHastaEntregado($entrega->domicilioEnvio->fresh());

        $this->assertSame(DomicilioEnvio::ESTADO_ENTREGADO, $envio->estado);
        $this->assertSame(Entrega::ESTADO_PARCIAL, $envio->entrega->fresh()->estado);

        $ticket->refresh();
        $this->assertSame(Ticket::ESTADO_PARCIAL, $ticket->estado);
        $this->assertNull($ticket->cerrado_en);
        $this->assertTrue($ticket->estaEntregable());

        $dtoTras = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);
        $pendientes = collect(app(SaldoTicket::class)->lineasPendientes($dtoTras))
            ->mapWithKeys(fn (array $l) => [$l['item']->codigo => $l['pendiente']]);

        $this->assertSame(['MED-002' => 15.0], $pendientes->all());

        Livewire::test(AtenderEntrega::class)
            ->fillForm(['ticket_numero' => $ticket->numero], 'busquedaForm')
            ->call('buscar')
            ->assertSet('ticketBloqueado', false)
            ->assertNotSet('ticket', null);

        $itemsUi = collect(Livewire::test(AtenderEntrega::class)
            ->fillForm(['ticket_numero' => $ticket->numero], 'busquedaForm')
            ->call('buscar')
            ->get('atencion')['items'] ?? [])
            ->mapWithKeys(fn (array $i) => [$i['codigo'] => (float) $i['cantidad_solicitada']]);

        $this->assertSame(['MED-002' => 15.0], $itemsUi->all());
    }

    public function test_d3_domicilio_con_faltante_total_conserva_pendiente_y_motivo(): void
    {
        $ticket = $this->ticketListo(items: [
            ['codigo' => 'MED-001', 'nombre' => 'ACETAMINOFEN 500 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
            ['codigo' => 'MED-003', 'nombre' => 'METFORMINA 850 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
        ]);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        $entrega = $this->registrarDomicilio($ticket->numero, $this->itemsDesdeDto($dto, [
            'MED-001' => 30,
            'MED-003' => 0,
        ], [
            'MED-003' => 'Faltante por disponibilidad',
        ]));

        $metformina = $entrega->items->firstWhere('codigo', 'MED-003');
        $this->assertSame(EntregaItem::RESULTADO_FALTANTE, $metformina->resultado);
        $this->assertSame(30.0, (float) $metformina->cantidad_pendiente);
        $this->assertSame('Faltante por disponibilidad', $metformina->motivo);

        $envio = $this->avanzarDomicilioHastaEntregado($entrega->domicilioEnvio->fresh());

        $this->assertSame(DomicilioEnvio::ESTADO_ENTREGADO, $envio->estado);
        $this->assertSame(Entrega::ESTADO_PARCIAL, $envio->entrega->fresh()->estado);

        $ticket->refresh();
        $this->assertSame(Ticket::ESTADO_PARCIAL, $ticket->estado);
        $this->assertNull($ticket->cerrado_en);

        $dtoTras = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);
        $pendientes = collect(app(SaldoTicket::class)->lineasPendientes($dtoTras))
            ->mapWithKeys(fn (array $l) => [$l['item']->codigo => $l['pendiente']]);

        $this->assertSame(['MED-003' => 30.0], $pendientes->all());
        $this->assertSame('Faltante por disponibilidad', $metformina->fresh()->motivo);
    }
}
