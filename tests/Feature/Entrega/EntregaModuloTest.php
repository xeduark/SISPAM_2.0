<?php

namespace Tests\Feature\Entrega;

use App\Contracts\Ticket\TicketConsultaInterface;
use App\Filament\Pages\AtenderEntrega;
use App\Filament\Pages\ReporteEntregas;
use App\Models\DomicilioEnvio;
use App\Models\Entrega;
use App\Models\EntregaItem;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\User;
use App\Services\Entrega\RegistrarEntrega;
use App\Services\Ticket\TicketConsultaMock;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-1001');

        $this->assertNotNull($ticket);
        $this->assertTrue($ticket->listoParaEntrega());
        $this->assertCount(3, $ticket->items);
        $this->assertInstanceOf(TicketConsultaMock::class, app(TicketConsultaInterface::class));
    }

    public function test_ticket_con_sufijo_ac_marca_alto_costo(): void
    {
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-9AC');

        $this->assertTrue($ticket->altoCosto);
    }

    public function test_registra_entrega_presencial_con_firma(): void
    {
        $usuario = User::factory()->administrador()->create();
        $paciente = Paciente::factory()->create();
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-200');

        $entrega = app(RegistrarEntrega::class)->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => $paciente->nombre_completo,
            'receptor_documento' => $paciente->numero_documento,
            'receptor_parentesco' => 'Paciente',
            'firma_contenido' => 'data:image/png;base64,'.base64_encode('firma-demo'),
            'items' => collect($ticket->items)->map(fn ($item) => [
                'ticket_item_id' => $item->id,
                'codigo' => $item->codigo,
                'nombre' => $item->nombre,
                'cantidad_solicitada' => $item->cantidad,
                'unidad' => $item->unidad,
                'cantidad_entregada' => $item->cantidad,
                'resultado' => EntregaItem::RESULTADO_ENTREGADO,
                'motivo' => null,
            ])->all(),
        ]);

        $this->assertSame(Entrega::ESTADO_COMPLETADA, $entrega->estado);
        $this->assertSame(Entrega::TIPO_PRESENCIAL, $entrega->tipo);
        $this->assertNotNull($entrega->firma_path);
        $this->assertCount(3, $entrega->items);
        $this->assertSame(Entrega::FACTURACION_PENDIENTE, $entrega->facturacion_estado);
    }

    public function test_entrega_parcial_por_faltante(): void
    {
        $usuario = User::factory()->administrador()->create();
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-201');

        $items = collect($ticket->items)->values()->map(function ($item, int $i) {
            return [
                'ticket_item_id' => $item->id,
                'codigo' => $item->codigo,
                'nombre' => $item->nombre,
                'cantidad_solicitada' => $item->cantidad,
                'unidad' => $item->unidad,
                'cantidad_entregada' => $i === 0 ? 0 : $item->cantidad,
                'resultado' => $i === 0 ? EntregaItem::RESULTADO_FALTANTE : EntregaItem::RESULTADO_ENTREGADO,
                'motivo' => $i === 0 ? 'Sin stock en sede' : null,
            ];
        })->all();

        $entrega = app(RegistrarEntrega::class)->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => 'Receptor',
            'receptor_documento' => '123',
            'firma_contenido' => 'firma-bytes',
            'items' => $items,
        ]);

        $this->assertSame(Entrega::ESTADO_PARCIAL, $entrega->estado);
        $this->assertSame(1, $entrega->items()->where('resultado', EntregaItem::RESULTADO_FALTANTE)->count());
    }

    public function test_domicilio_crea_envio_y_cambia_estado_con_mock_domina(): void
    {
        $usuario = User::factory()->administrador()->create();
        Paciente::factory()->create();
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-300');

        $entrega = app(RegistrarEntrega::class)->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_DOMICILIO,
            'items' => collect($ticket->items)->map(fn ($item) => [
                'ticket_item_id' => $item->id,
                'codigo' => $item->codigo,
                'nombre' => $item->nombre,
                'cantidad_solicitada' => $item->cantidad,
                'unidad' => $item->unidad,
                'cantidad_entregada' => $item->cantidad,
                'resultado' => EntregaItem::RESULTADO_ENTREGADO,
            ])->all(),
        ]);

        $envio = $entrega->domicilioEnvio;
        $this->assertNotNull($envio);
        $this->assertSame(DomicilioEnvio::ESTADO_PENDIENTE_ENVIO, $envio->estado);
        $this->assertCount(1, $envio->historial);

        $envio = app(RegistrarEntrega::class)->cambiarEstadoDomicilio(
            $envio,
            DomicilioEnvio::ESTADO_ENVIADO_DOMINA,
            $usuario,
            'Despacho a Dómina (mock)',
        );

        $this->assertSame(DomicilioEnvio::ESTADO_ENVIADO_DOMINA, $envio->estado);
        $this->assertNotNull($envio->referencia_externa);
        $this->assertStringStartsWith('MOCK-', $envio->referencia_externa);
        $this->assertGreaterThanOrEqual(2, $envio->historial()->count());
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
}
