<?php

namespace Tests\Feature\Entrega;

use App\Contracts\Ticket\Dto\TicketDto;
use App\Contracts\Ticket\TicketConsultaInterface;
use App\Filament\Pages\AtenderEntrega;
use App\Filament\Pages\ReporteEntregas;
use App\Models\DomicilioEnvio;
use App\Models\Entrega;
use App\Models\EntregaItem;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Services\Entrega\RegistrarEntrega;
use App\Services\Entrega\SaldoTicket;
use App\Services\Ticket\TicketConsultaMock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class EntregaModuloTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Estas pruebas son del módulo de entrega, no de dónde salen los tickets.
     *
     * En producción el binding ya apunta a `TicketConsultaDb` (los tickets
     * reales); aquí se fija el mock a propósito, que es el contrato mínimo
     * que entrega necesita y mantiene estas pruebas independientes del
     * módulo de ticket.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(TicketConsultaInterface::class, TicketConsultaMock::class);
    }

    public function test_el_mock_de_ticket_devuelve_medicamentos(): void
    {
        $usuario = User::factory()->administrador()->create();
        $this->actingAs($usuario);
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-1001');

        $this->assertNotNull($ticket);
        $this->assertTrue($ticket->listoParaEntrega());
        $this->assertCount(3, $ticket->items);
        $this->assertSame(30.0, (float) $ticket->items[0]->cantidad);
        $this->assertInstanceOf(TicketConsultaMock::class, app(TicketConsultaInterface::class));
    }

    public function test_entrega_completa_presencial_con_firma(): void
    {
        $usuario = User::factory()->administrador()->create();
        Paciente::factory()->create();
        $this->actingAs($usuario);
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-1001');

        $entrega = app(RegistrarEntrega::class)->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => 'Receptor Prueba',
            'receptor_documento' => '123456',
            'receptor_parentesco' => 'Paciente',
            'firma_contenido' => 'data:image/png;base64,'.base64_encode('firma-demo'),
            'items' => $this->itemsCompletos($ticket),
        ]);

        $this->assertSame(Entrega::ESTADO_COMPLETADA, $entrega->estado);
        $this->assertNotNull($entrega->firma_path);
        $this->assertSame(0.0, (float) $entrega->items->sum('cantidad_pendiente'));
        $this->assertTrue(app(SaldoTicket::class)->ticketCompletamenteDispensado($ticket));
    }

    public function test_entrega_parcial_con_faltante_total_y_parcial(): void
    {
        $usuario = User::factory()->administrador()->create();
        Paciente::factory()->create();
        $this->actingAs($usuario);
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-MIX');

        $entrega = app(RegistrarEntrega::class)->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => 'Receptor',
            'receptor_documento' => '999',
            'firma_contenido' => 'firma',
            'items' => [
                $this->itemDatos($ticket->items[0], 30, EntregaItem::RESULTADO_ENTREGADO),
                $this->itemDatos($ticket->items[1], 15, EntregaItem::RESULTADO_ENTREGADO, 'Solo había 15'),
                $this->itemDatos($ticket->items[2], 0, EntregaItem::RESULTADO_FALTANTE, 'Faltante por disponibilidad'),
            ],
        ]);

        $porCodigo = $entrega->items->keyBy('codigo');
        $this->assertSame(EntregaItem::RESULTADO_ENTREGADO, $porCodigo['MED-001']->resultado);
        $this->assertSame(0.0, (float) $porCodigo['MED-001']->cantidad_pendiente);
        $this->assertSame(EntregaItem::RESULTADO_PARCIAL, $porCodigo['MED-002']->resultado);
        $this->assertSame(15.0, (float) $porCodigo['MED-002']->cantidad_pendiente);
        $this->assertSame(EntregaItem::RESULTADO_FALTANTE, $porCodigo['MED-003']->resultado);
        $this->assertSame(30.0, (float) $porCodigo['MED-003']->cantidad_pendiente);
        $this->assertSame('Faltante por disponibilidad', $porCodigo['MED-003']->motivo);
        $this->assertSame(Entrega::ESTADO_PARCIAL, $entrega->estado);
    }

    public function test_segunda_entrega_solo_sobre_pendiente(): void
    {
        $usuario = User::factory()->administrador()->create();
        Paciente::factory()->create();
        $this->actingAs($usuario);
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-REAT');
        $registrar = app(RegistrarEntrega::class);

        $registrar->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => 'A',
            'receptor_documento' => '1',
            'firma_contenido' => 'f1',
            'items' => [
                $this->itemDatos($ticket->items[0], 30),
                $this->itemDatos($ticket->items[1], 15, EntregaItem::RESULTADO_PARCIAL, 'Parcial'),
                $this->itemDatos($ticket->items[2], 0, EntregaItem::RESULTADO_FALTANTE, 'Sin stock'),
            ],
        ]);

        $pendientes = app(SaldoTicket::class)->lineasPendientes($ticket);
        $this->assertCount(2, $pendientes);
        $this->assertSame(15.0, $pendientes[0]['pendiente']);
        $this->assertSame(30.0, $pendientes[1]['pendiente']);

        $segunda = $registrar->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => 'A',
            'receptor_documento' => '1',
            'firma_contenido' => 'f2',
            'items' => [
                [
                    'ticket_item_id' => $ticket->items[1]->id,
                    'codigo' => $ticket->items[1]->codigo,
                    'nombre' => $ticket->items[1]->nombre,
                    'cantidad_solicitada' => 15,
                    'unidad' => 'TAB',
                    'cantidad_entregada' => 15,
                    'resultado' => EntregaItem::RESULTADO_ENTREGADO,
                ],
                [
                    'ticket_item_id' => $ticket->items[2]->id,
                    'codigo' => $ticket->items[2]->codigo,
                    'nombre' => $ticket->items[2]->nombre,
                    'cantidad_solicitada' => 30,
                    'unidad' => 'TAB',
                    'cantidad_entregada' => 30,
                    'resultado' => EntregaItem::RESULTADO_ENTREGADO,
                ],
            ],
        ]);

        $this->assertSame(Entrega::ESTADO_COMPLETADA, $segunda->estado);
        $this->assertTrue(app(SaldoTicket::class)->ticketCompletamenteDispensado($ticket));
    }

    public function test_bloquea_ticket_completamente_dispensado(): void
    {
        $usuario = User::factory()->administrador()->create();
        Paciente::factory()->create();
        $this->actingAs($usuario);
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-DONE');
        $registrar = app(RegistrarEntrega::class);

        $registrar->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => 'A',
            'receptor_documento' => '1',
            'firma_contenido' => 'f',
            'items' => $this->itemsCompletos($ticket),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ya fue dispensado completamente');

        $registrar->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => 'A',
            'receptor_documento' => '1',
            'firma_contenido' => 'f',
            'items' => $this->itemsCompletos($ticket),
        ]);
    }

    public function test_no_permite_entregar_mas_de_lo_pendiente(): void
    {
        $usuario = User::factory()->administrador()->create();
        Paciente::factory()->create();
        $this->actingAs($usuario);
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-OVER');
        $registrar = app(RegistrarEntrega::class);

        $registrar->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => 'A',
            'receptor_documento' => '1',
            'firma_contenido' => 'f',
            'items' => [
                $this->itemDatos($ticket->items[0], 20, EntregaItem::RESULTADO_PARCIAL, 'Parcial'),
                $this->itemDatos($ticket->items[1], 30),
                $this->itemDatos($ticket->items[2], 30),
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $registrar->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => 'A',
            'receptor_documento' => '1',
            'firma_contenido' => 'f',
            'items' => [[
                'ticket_item_id' => $ticket->items[0]->id,
                'codigo' => $ticket->items[0]->codigo,
                'nombre' => $ticket->items[0]->nombre,
                'cantidad_solicitada' => 10,
                'unidad' => 'TAB',
                'cantidad_entregada' => 20,
                'resultado' => EntregaItem::RESULTADO_ENTREGADO,
            ]],
        ]);
    }

    public function test_presencial_exige_receptor_y_firma(): void
    {
        $usuario = User::factory()->administrador()->create();
        $this->actingAs($usuario);
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-SIG');

        $this->expectException(\InvalidArgumentException::class);

        app(RegistrarEntrega::class)->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'items' => $this->itemsCompletos($ticket),
        ]);
    }

    public function test_domicilio_no_exige_firma_y_respeta_transiciones(): void
    {
        $usuario = User::factory()->administrador()->create();
        Paciente::factory()->create();
        $this->actingAs($usuario);
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-DOM');
        $registrar = app(RegistrarEntrega::class);

        $entrega = $registrar->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_DOMICILIO,
            'items' => $this->itemsCompletos($ticket),
        ]);

        $this->assertNull($entrega->firma_path);
        $envio = $entrega->domicilioEnvio;
        $this->assertSame(DomicilioEnvio::ESTADO_PENDIENTE_ENVIO, $envio->estado);

        $this->expectException(\InvalidArgumentException::class);
        $registrar->cambiarEstadoDomicilio($envio, DomicilioEnvio::ESTADO_ENTREGADO, $usuario);
    }

    public function test_cambio_estado_domicilio_valido_con_historial(): void
    {
        $usuario = User::factory()->administrador()->create();
        Paciente::factory()->create();
        $this->actingAs($usuario);
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-DOM2');
        $registrar = app(RegistrarEntrega::class);

        $envio = $registrar->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_DOMICILIO,
            'items' => $this->itemsCompletos($ticket),
        ])->domicilioEnvio;

        $envio = $registrar->cambiarEstadoDomicilio($envio, DomicilioEnvio::ESTADO_PREPARADO, $usuario, 'Listo');
        $envio = $registrar->cambiarEstadoDomicilio($envio, DomicilioEnvio::ESTADO_ENVIADO_DOMINA, $usuario, 'Despacho');

        $this->assertSame(DomicilioEnvio::ESTADO_ENVIADO_DOMINA, $envio->estado);
        $this->assertStringStartsWith('MOCK-', (string) $envio->referencia_externa);
        $this->assertGreaterThanOrEqual(3, $envio->historial()->count());
    }

    public function test_pantalla_bloquea_ticket_completo(): void
    {
        $usuario = User::factory()->administrador()->create();
        Paciente::factory()->create();
        $this->actingAs($usuario);
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-UI');

        app(RegistrarEntrega::class)->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => 'A',
            'receptor_documento' => '1',
            'firma_contenido' => 'f',
            'items' => $this->itemsCompletos($ticket),
        ]);

        $this->actingAs($usuario);

        Livewire::test(AtenderEntrega::class)
            ->set('busqueda.ticket_numero', 'T-UI')
            ->call('buscar')
            ->assertSet('ticketBloqueado', true)
            ->assertSee('Dispensación bloqueada')
            ->assertSee('ya fue dispensado completamente')
            ->assertDontSee('No se encontró un ticket disponible')
            ->assertDontSee('Confirmar y registrar entrega');
    }

    public function test_pantalla_bloquea_ticket_ya_entregado_sin_decir_sin_ticket(): void
    {
        $usuario = User::factory()->administrador()->create();
        Paciente::factory()->create();
        $this->actingAs($usuario);

        $base = app(TicketConsultaInterface::class)->buscarPorNumero('T-ENTREGADO');
        $this->assertNotNull($base);

        $this->app->instance(TicketConsultaInterface::class, new class($base) implements TicketConsultaInterface
        {
            public function __construct(private $base) {}

            public function buscarPorNumero(string $numero): ?TicketDto
            {
                return new TicketDto(
                    numero: $this->base->numero,
                    estado: 'entregado',
                    sedeId: $this->base->sedeId,
                    paciente: $this->base->paciente,
                    items: $this->base->items,
                    altoCosto: $this->base->altoCosto,
                    turno: $this->base->turno,
                );
            }
        });

        Livewire::test(AtenderEntrega::class)
            ->set('busqueda.ticket_numero', 'T-ENTREGADO')
            ->call('buscar')
            ->assertSet('ticketBloqueado', true)
            ->assertSet('ticket.numero', 'T-ENTREGADO')
            ->assertSee('Dispensación bloqueada')
            ->assertSee('ya fue dispensado completamente')
            ->assertDontSee('No se encontró un ticket disponible')
            ->assertDontSee('Confirmar y registrar entrega')
            ->call('registrar')
            ->assertSet('ticketBloqueado', true);

        $this->assertDatabaseCount('entregas', 0);
    }

    public function test_contenido_firma_lee_ruta_en_disco_local(): void
    {
        Storage::fake('local');
        $usuario = User::factory()->administrador()->create();
        $this->actingAs($usuario);

        $ruta = 'soportes/firmas-entrega/tmp/firma-regresion.png';
        Storage::disk('local')->put($ruta, 'bytes-firma-ok');

        $page = new AtenderEntrega;
        $ref = new \ReflectionMethod(AtenderEntrega::class, 'contenidoFirma');
        $ref->setAccessible(true);

        $contenido = $ref->invoke($page, ['uuid-demo' => $ruta]);

        $this->assertSame('bytes-firma-ok', $contenido);
        $this->assertNull($ref->invoke($page, null));
        $this->assertNull($ref->invoke($page, []));
    }

    public function test_permisos_del_modulo_entrega(): void
    {
        Rol::create([
            'nombre' => 'DISPENSADOR',
            'permisos' => ['entrega' => ['ver', 'atender']],
        ]);
        $usuario = User::factory()->create(['roles' => ['DISPENSADOR']]);
        $this->actingAs($usuario);

        $this->get('/admin/atender-entrega')->assertOk();
        $this->get('/admin/entregas')->assertOk();
        $this->get('/admin/reporte-entregas')->assertForbidden();

        $sinPermiso = User::factory()->create();
        $this->actingAs($sinPermiso);
        $this->get('/admin/atender-entrega')->assertForbidden();
    }

    public function test_administrador_entra_a_paginas_de_entrega(): void
    {
        $this->actingAs(User::factory()->administrador()->create());

        Livewire::test(AtenderEntrega::class)->assertSuccessful();
        Livewire::test(ReporteEntregas::class)->assertSuccessful();
        $this->get('/admin/domicilio-envios')->assertOk();
    }

    public function test_entrega_conserva_sede_del_ticket_aunque_cambie_la_del_usuario(): void
    {
        $sedeCentro = Sede::factory()->create(['nombre' => 'Sede Centro', 'activa' => true]);
        $sedeNorte = Sede::factory()->create(['nombre' => 'Sede Norte', 'activa' => true]);
        $usuario = User::factory()->administrador()->create(['sede_id' => $sedeCentro->id]);
        Paciente::factory()->create();
        $this->actingAs($usuario);
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-SEDE');
        $this->assertSame($sedeCentro->id, $ticket->sedeId);

        $entrega = app(RegistrarEntrega::class)->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'sede_id' => $sedeCentro->id,
            'receptor_nombre' => 'Receptor',
            'receptor_documento' => '1',
            'firma_contenido' => 'firma',
            'items' => $this->itemsCompletos($ticket),
        ]);

        $this->assertSame($sedeCentro->id, $entrega->sede_id);
        $this->assertSame('Sede Centro', $entrega->sede->nombre);

        // El usuario pasa a Norte; la entrega histórica sigue en Centro.
        $usuario->update(['sede_id' => $sedeNorte->id]);
        $entrega->refresh()->load('sede');

        $this->assertSame($sedeCentro->id, $entrega->sede_id);
        $this->assertSame('Sede Centro', $entrega->sede->nombre);
        $this->assertSame($sedeNorte->id, $usuario->fresh()->sede_id);
    }

    public function test_no_permite_registrar_entrega_en_sede_distinta_a_la_del_ticket(): void
    {
        $sedeCentro = Sede::factory()->create(['nombre' => 'Sede Centro', 'activa' => true]);
        $sedeNorte = Sede::factory()->create(['nombre' => 'Sede Norte', 'activa' => true]);
        $usuario = User::factory()->administrador()->create(['sede_id' => $sedeCentro->id]);
        Paciente::factory()->create();
        $this->actingAs($usuario);
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-SEDE-CROSS');

        try {
            app(RegistrarEntrega::class)->handle($ticket, $usuario, [
                'tipo' => Entrega::TIPO_PRESENCIAL,
                'sede_id' => $sedeNorte->id,
                'receptor_nombre' => 'A',
                'receptor_documento' => '1',
                'firma_contenido' => 'f',
                'items' => $this->itemsCompletos($ticket),
            ]);
            $this->fail('Debió rechazar la entrega en sede distinta.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString(
                'Este ticket pertenece a la Sede Centro y debe ser atendido en esa sede.',
                $e->getMessage(),
            );
        }

        $this->assertDatabaseCount('entregas', 0);
    }

    public function test_no_permite_sede_inactiva_del_ticket(): void
    {
        $sedeInactiva = Sede::factory()->inactiva()->create(['nombre' => 'Sede Cerrada']);
        $usuario = User::factory()->administrador()->create(['sede_id' => $sedeInactiva->id]);
        Paciente::factory()->create();
        $this->actingAs($usuario);
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-SEDE-OFF');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no está activa');

        app(RegistrarEntrega::class)->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'sede_id' => $sedeInactiva->id,
            'receptor_nombre' => 'A',
            'receptor_documento' => '1',
            'firma_contenido' => 'f',
            'items' => $this->itemsCompletos($ticket),
        ]);
    }

    /**
     * @param  object{id: string, codigo: string, nombre: string, cantidad: float|int, unidad: string}  $item
     * @return array<string, mixed>
     */
    private function itemDatos(object $item, float|int $entregada, ?string $resultado = null, ?string $motivo = null): array
    {
        return [
            'ticket_item_id' => $item->id,
            'codigo' => $item->codigo,
            'nombre' => $item->nombre,
            'cantidad_solicitada' => $item->cantidad,
            'unidad' => $item->unidad,
            'cantidad_entregada' => $entregada,
            'resultado' => $resultado ?? EntregaItem::RESULTADO_ENTREGADO,
            'motivo' => $motivo,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itemsCompletos(object $ticket): array
    {
        return collect($ticket->items)
            ->map(fn ($item) => $this->itemDatos($item, $item->cantidad))
            ->all();
    }
}
