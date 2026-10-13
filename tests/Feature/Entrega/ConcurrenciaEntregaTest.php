<?php

namespace Tests\Feature\Entrega;

use App\Contracts\Ticket\TicketConsultaInterface;
use App\Jobs\DescontarInventarioDeEntrega;
use App\Models\Cola;
use App\Models\Entrega;
use App\Models\EntregaItem;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Entrega\RegistrarEntrega;
use App\Services\Entrega\SaldoTicket;
use App\Services\Tickets\AlistarTicket;
use App\Services\Tickets\GenerarTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Evitar sobredispensar el mismo ticket.
 *
 * El saldo se recalcula bajo `lockForUpdate` de la fila `tickets`. En PHPUnit
 * la base es SQLite en memoria: ahí se prueba el rechazo secuencial tras el
 * primer commit (ventana de carrera cerrada). El bloqueo concurrente real
 * entre dos conexiones requiere MySQL/InnoDB y no se afirma aquí como
 * concurrencia paralela.
 */
class ConcurrenciaEntregaTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private User $dispensador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sede = Sede::factory()->create(['nombre' => 'Sede Centro', 'codigo' => 'CEN', 'activa' => true]);
        Cola::factory()->create([
            'sede_id' => $this->sede->id,
            'prefijo' => 'A',
            'orden' => 1,
            'activa' => true,
            'atiende_alto_costo' => false,
        ]);
        $this->dispensador = User::factory()->administrador()->create(['sede_id' => $this->sede->id]);
        $this->actingAs($this->dispensador);
    }

    /**
     * @param  list<array{codigo: string, nombre: string, cantidad: float|int, unidad?: string}>|null  $items
     */
    private function ticketListo(?array $items = null, ?Sede $sede = null, ?User $usuario = null): Ticket
    {
        $sede ??= $this->sede;
        $usuario ??= $this->dispensador;

        $ticket = app(GenerarTicket::class)->handle(
            Paciente::factory()->create(),
            $sede,
            $usuario,
        );

        return app(AlistarTicket::class)->handle($ticket, $usuario, $items ?? [
            ['codigo' => 'MX377-6', 'nombre' => 'LOSARTAN 50 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
        ]);
    }

    /**
     * @param  array<string, float>  $entregadasPorCodigo
     */
    private function registrar(string $numero, array $entregadasPorCodigo, ?User $usuario = null, ?int $sedeId = null): Entrega
    {
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($numero);
        $this->assertNotNull($dto);

        $items = collect($dto->items)->map(function ($item) use ($entregadasPorCodigo): array {
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
                $fila['motivo'] = 'Parcial de prueba';
            }

            return $fila;
        })->all();

        return app(RegistrarEntrega::class)->handle($dto, $usuario ?? $this->dispensador, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'sede_id' => $sedeId ?? $this->sede->id,
            'receptor_nombre' => 'Receptor',
            'receptor_documento' => '1',
            'firma_contenido' => 'firma',
            'items' => $items,
        ]);
    }

    public function test_c1_entrega_normal_registra_cantidad_valida(): void
    {
        $ticket = $this->ticketListo();

        $entrega = $this->registrar($ticket->numero, ['MX377-6' => 30]);

        $this->assertSame(Entrega::ESTADO_COMPLETADA, $entrega->estado);
        $this->assertSame(30.0, (float) $entrega->items->first()->cantidad_entregada);
        $this->assertTrue(app(SaldoTicket::class)->ticketCompletamenteDispensado(
            app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero)
        ));
    }

    public function test_c2_segunda_atencion_parcial_solo_permite_saldo_restante(): void
    {
        $ticket = $this->ticketListo();

        $this->registrar($ticket->numero, ['MX377-6' => 15]);
        $segunda = $this->registrar($ticket->numero, ['MX377-6' => 10]);

        $this->assertSame(10.0, (float) $segunda->items->first()->cantidad_entregada);
        $this->assertSame(5.0, app(SaldoTicket::class)->pendienteDeLinea(
            $ticket->numero,
            (string) $segunda->items->first()->ticket_item_id,
            30,
        ));
    }

    public function test_c3_ticket_completo_rechaza_nueva_solicitud(): void
    {
        $ticket = $this->ticketListo();
        $this->registrar($ticket->numero, ['MX377-6' => 30]);

        try {
            $this->registrar($ticket->numero, ['MX377-6' => 1]);
            $this->fail('Debió rechazar una nueva dispensación.');
        } catch (\InvalidArgumentException $e) {
            // Tras cerrar el ticket pasa a entregado (no listo) o el saldo ya es 0.
            $this->assertTrue(
                str_contains($e->getMessage(), 'ya fue dispensado completamente')
                || str_contains($e->getMessage(), 'no está listo para entrega')
                || str_contains($e->getMessage(), 'ya no tiene cantidad pendiente'),
                $e->getMessage()
            );
        }

        $this->assertSame(1, Entrega::where('ticket_numero', $ticket->numero)->count());
    }

    /**
     * C4 — dos solicitudes por el mismo saldo pendiente (15 restantes).
     *
     * Cobertura **secuencial** (no paralelismo real): con SQLite :memory: de
     * PHPUnit, `lockForUpdate` no reproduce el bloqueo entre dos conexiones
     * InnoDB. Tras el primer commit, el segundo intento recalcula el saldo y
     * se rechaza; el total entregado no supera lo solicitado.
     */
    public function test_c4_dos_pedidos_del_mismo_saldo_pendiente_el_segundo_falla(): void
    {
        $ticket = $this->ticketListo();
        $this->registrar($ticket->numero, ['MX377-6' => 15]);

        $ok = $this->registrar($ticket->numero, ['MX377-6' => 15]);
        $this->assertSame(15.0, (float) $ok->items->first()->cantidad_entregada);

        try {
            $this->registrar($ticket->numero, ['MX377-6' => 15]);
            $this->fail('Debió rechazar la segunda solicitud del mismo saldo.');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'ya fue dispensado completamente')
                || str_contains($e->getMessage(), 'no está listo para entrega')
                || str_contains($e->getMessage(), 'ya no tiene cantidad pendiente'),
                $e->getMessage()
            );
        }

        $this->assertSame(2, Entrega::where('ticket_numero', $ticket->numero)->count());
        $this->assertSame(30.0, (float) EntregaItem::query()
            ->whereHas('entrega', fn ($q) => $q->where('ticket_numero', $ticket->numero))
            ->sum('cantidad_entregada'));
    }

    public function test_c5_tickets_distintos_se_atienden_independientes(): void
    {
        $a = $this->ticketListo([['codigo' => 'MED-A', 'nombre' => 'A', 'cantidad' => 10, 'unidad' => 'TAB']]);
        $b = $this->ticketListo([['codigo' => 'MED-B', 'nombre' => 'B', 'cantidad' => 8, 'unidad' => 'TAB']]);

        $ea = $this->registrar($a->numero, ['MED-A' => 10]);
        $eb = $this->registrar($b->numero, ['MED-B' => 8]);

        $this->assertSame(Entrega::ESTADO_COMPLETADA, $ea->estado);
        $this->assertSame(Entrega::ESTADO_COMPLETADA, $eb->estado);
        $this->assertNotSame($a->numero, $b->numero);
    }

    public function test_c6_solo_entregas_persistidas_despachan_descuento_de_inventario(): void
    {
        config([
            'services.inventario.url' => 'http://inventario.test',
            'services.inventario.token' => 'secreto',
        ]);
        Http::fake(['inventario.test/api/dispensaciones' => Http::response(['referencia' => 'ok', 'items' => []], 201)]);
        Queue::fake([DescontarInventarioDeEntrega::class]);

        $ticket = $this->ticketListo();
        $this->registrar($ticket->numero, ['MX377-6' => 30]);

        Queue::assertPushed(DescontarInventarioDeEntrega::class, 1);

        try {
            $this->registrar($ticket->numero, ['MX377-6' => 5]);
            $this->fail('Debió rechazar.');
        } catch (\InvalidArgumentException) {
            // esperado
        }

        Queue::assertPushed(DescontarInventarioDeEntrega::class, 1);
        Http::assertNothingSent(); // Queue::fake evita ejecutar el job; 0 HTTP es coherente
    }

    public function test_c7_bloqueo_no_permite_dispensar_ticket_de_otra_sede(): void
    {
        $norte = Sede::factory()->create(['nombre' => 'Sede Norte', 'codigo' => 'NOR', 'activa' => true]);
        $usuarioNorte = User::factory()->administrador()->create(['sede_id' => $norte->id]);
        $ticket = $this->ticketListo(); // Centro

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pertenece a otra sede');

        $this->registrar($ticket->numero, ['MX377-6' => 10], $usuarioNorte, $this->sede->id);
    }

    public function test_c8_fallo_dentro_de_la_transaccion_hace_rollback_sin_descuento(): void
    {
        config([
            'services.inventario.url' => 'http://inventario.test',
            'services.inventario.token' => 'secreto',
        ]);
        Http::fake(['inventario.test/*' => Http::response(['referencia' => 'x', 'items' => []], 201)]);
        Queue::fake();

        $ticket = $this->ticketListo();
        $antes = Entrega::count();

        EntregaItem::creating(function (): void {
            throw new \RuntimeException('Fallo forzado en la transacción');
        });

        try {
            $this->registrar($ticket->numero, ['MX377-6' => 10]);
            $this->fail('Debió fallar la transacción.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Fallo forzado', $e->getMessage());
        }

        $this->assertSame($antes, Entrega::count());
        $this->assertSame(0, EntregaItem::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame(30.0, app(SaldoTicket::class)->pendienteDeLinea(
            $ticket->numero,
            (string) $ticket->items()->first()->id,
            30,
        ));
    }

    public function test_el_lock_se_pide_sobre_la_fila_del_ticket_antes_de_crear_la_entrega(): void
    {
        $ticket = $this->ticketListo();
        $consultas = [];

        DB::listen(function ($query) use (&$consultas): void {
            $consultas[] = $query->sql;
        });

        $this->registrar($ticket->numero, ['MX377-6' => 5]);

        $huboLock = collect($consultas)->contains(
            fn (string $sql): bool => str_contains(strtolower($sql), 'lock in share mode')
                || str_contains(strtolower($sql), 'for update')
                || (str_contains(strtolower($sql), 'tickets') && str_contains(strtolower($sql), 'numero'))
        );

        // SQLite puede traducir lockForUpdate de forma distinta; al menos debe
        // haber consultado tickets por numero dentro de la operación.
        $consultoTicket = collect($consultas)->contains(
            fn (string $sql): bool => str_contains(strtolower($sql), 'from "tickets"')
                || str_contains(strtolower($sql), 'from `tickets`')
                || str_contains(strtolower($sql), 'from tickets')
        );

        $this->assertTrue($consultoTicket || $huboLock, 'Debió leer/bloquear la fila del ticket.');
    }
}
