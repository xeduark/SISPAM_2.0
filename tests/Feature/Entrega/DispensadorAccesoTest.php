<?php

namespace Tests\Feature\Entrega;

use App\Contracts\Ticket\TicketConsultaInterface;
use App\Filament\Resources\EntregaResource;
use App\Models\Entrega;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\User;
use App\Services\Entrega\RegistrarEntrega;
use App\Services\Entrega\SaldoTicket;
use App\Services\Ticket\TicketConsultaMock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Acceso real del rol DISPENSADOR (no administrador).
 */
class DispensadorAccesoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Las firmas de prueba no deben caer en storage/app/private real (pisaban las de las entregas locales).
        Storage::fake('local');
    }

    private function dispensador(array $accionesEntrega = ['ver', 'atender', 'domicilio', 'reportes']): User
    {
        Rol::create([
            'nombre' => 'DISPENSADOR',
            'permisos' => ['entrega' => $accionesEntrega],
        ]);

        return User::factory()->create([
            'documento' => 'DispensadorPrueba',
            'roles' => ['DISPENSADOR'],
            'es_administrador' => false,
        ]);
    }

    public function test_dispensador_completo_entra_a_entrega_y_no_a_administracion(): void
    {
        $this->actingAs($this->dispensador());

        $this->get('/admin/atender-entrega')->assertOk();
        $this->get('/admin/entregas')->assertOk();
        $this->get('/admin/domicilio-envios')->assertOk();
        $this->get('/admin/reporte-entregas')->assertOk();

        $this->get('/admin/sedes')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/roles')->assertForbidden();
        $this->get('/admin/pacientes')->assertForbidden();
        $this->get('/admin/consultar-paciente')->assertForbidden();
    }

    public function test_sin_permisos_de_entrega_no_entra_por_url(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [], 'es_administrador' => false]));

        foreach (['atender-entrega', 'entregas', 'domicilio-envios', 'reporte-entregas'] as $ruta) {
            $this->get("/admin/{$ruta}")->assertForbidden();
        }
    }

    public function test_solo_ver_no_puede_atender_ni_reportes(): void
    {
        $this->actingAs($this->dispensador(['ver']));

        $this->get('/admin/entregas')->assertOk();
        $this->get('/admin/atender-entrega')->assertForbidden();
        $this->get('/admin/reporte-entregas')->assertForbidden();
    }

    public function test_solo_atender_puede_pantalla_de_atencion(): void
    {
        $this->actingAs($this->dispensador(['atender']));

        $this->get('/admin/atender-entrega')->assertOk();
        $this->get('/admin/reporte-entregas')->assertForbidden();
    }

    public function test_no_puede_crear_entrega_por_recurso_filament(): void
    {
        $this->actingAs($this->dispensador());

        $this->assertFalse(EntregaResource::canCreate());
        // Sin página de creación registrada (canCreate=false) la URL no existe o responde 403.
        $this->get('/admin/entregas/create')->assertStatus(404);
    }

    public function test_saldo_nunca_supera_cantidad_original_del_ticket(): void
    {
        // Esta prueba es sobre la aritmética del saldo, no sobre de dónde sale el
        // ticket: el mock da tres líneas parejas y evita montar todo el alistamiento.
        $this->app->bind(TicketConsultaInterface::class, TicketConsultaMock::class);

        $usuario = User::factory()->administrador()->create();
        Paciente::factory()->create();
        $this->actingAs($usuario);
        $ticket = app(TicketConsultaInterface::class)->buscarPorNumero('T-SALDO');
        $this->assertNotNull($ticket);
        $original = (float) $ticket->items[0]->cantidad;

        app(RegistrarEntrega::class)->handle($ticket, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'receptor_nombre' => 'A',
            'receptor_documento' => '1',
            'firma_contenido' => 'f',
            'items' => [[
                'ticket_item_id' => $ticket->items[0]->id,
                'codigo' => $ticket->items[0]->codigo,
                'nombre' => $ticket->items[0]->nombre,
                'cantidad_solicitada' => $original,
                'unidad' => 'TAB',
                'cantidad_entregada' => 10,
                'resultado' => 'parcial',
                'motivo' => 'Parcial',
            ], [
                'ticket_item_id' => $ticket->items[1]->id,
                'codigo' => $ticket->items[1]->codigo,
                'nombre' => $ticket->items[1]->nombre,
                'cantidad_solicitada' => $ticket->items[1]->cantidad,
                'unidad' => 'TAB',
                'cantidad_entregada' => $ticket->items[1]->cantidad,
                'resultado' => 'entregado',
            ], [
                'ticket_item_id' => $ticket->items[2]->id,
                'codigo' => $ticket->items[2]->codigo,
                'nombre' => $ticket->items[2]->nombre,
                'cantidad_solicitada' => $ticket->items[2]->cantidad,
                'unidad' => 'TAB',
                'cantidad_entregada' => $ticket->items[2]->cantidad,
                'resultado' => 'entregado',
            ]],
        ]);

        $pendiente = app(SaldoTicket::class)->pendienteDeLinea($ticket->numero, $ticket->items[0]->id, $original);
        $this->assertSame(20.0, $pendiente);

        $acumulado = app(SaldoTicket::class)->entregadoAcumulado($ticket->numero);
        $this->assertLessThanOrEqual($original, $acumulado[$ticket->items[0]->id]);
    }

    public function test_dispensador_no_edita_matriz_ni_usuarios(): void
    {
        $user = $this->dispensador();
        $this->actingAs($user);

        $otro = User::factory()->create();
        $this->get('/admin/users/'.$otro->id.'/edit')->assertForbidden();
        $this->get('/admin/roles')->assertForbidden();
    }
}
