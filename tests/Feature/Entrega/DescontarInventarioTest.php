<?php

namespace Tests\Feature\Entrega;

use App\Contracts\Ticket\TicketConsultaInterface;
use App\Jobs\DescontarInventarioDeEntrega;
use App\Models\Cola;
use App\Models\DomicilioEnvio;
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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Enganche Entrega → Inventario (DescontarInventarioDeEntrega).
 *
 * Ninguna prueba toca inventario-api real. Documentan el contrato que SISPAM
 * envía hoy: cantidad_entregada se manda como `presentaciones` (ver comentario
 * del job: la conversión gotas→frascos, si aplica, ocurre antes en transcripción).
 */
class DescontarInventarioTest extends TestCase
{
    use RefreshDatabase;

    private function configurarApi(): void
    {
        config([
            'services.inventario.url' => 'http://inventario.test',
            'services.inventario.token' => 'secreto',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function entregaConItems(array $items, ?Sede $sede = null, string $tipo = Entrega::TIPO_PRESENCIAL): Entrega
    {
        $sede ??= Sede::factory()->create(['nombre' => 'LA 30', 'codigo' => 'L30']);

        $entrega = Entrega::create([
            'ticket_numero' => 'TK-PRUEBA-'.uniqid(),
            'sede_id' => $sede->id,
            'usuario_id' => User::factory()->create(['sede_id' => $sede->id])->id,
            'tipo' => $tipo,
            'estado' => Entrega::ESTADO_EN_PROCESO,
        ]);

        $entrega->items()->createMany($items);

        return $entrega->fresh(['items', 'sede', 'usuario']);
    }

    private function ticketListo(Sede $sede, User $usuario, array $items): Ticket
    {
        Cola::factory()->create([
            'sede_id' => $sede->id,
            'prefijo' => 'A',
            'orden' => 1,
            'activa' => true,
            'atiende_alto_costo' => false,
        ]);

        $ticket = app(GenerarTicket::class)->handle(
            Paciente::factory()->create([
                'telefono_movil' => '3001112233',
                'direccion' => 'Calle 1 # 2-3',
                'contacto_confirmado_at' => now(),
            ]),
            $sede,
            $usuario,
        );

        return app(AlistarTicket::class)->handle($ticket, $usuario, $items);
    }

    /* ------------------------------------------------------------------ *
     *  Casos base ya existentes
     * ------------------------------------------------------------------ */

    public function test_al_cerrar_la_entrega_descuenta_lo_entregado(): void
    {
        $this->configurarApi();
        Http::fake(['inventario.test/api/dispensaciones' => Http::response(['referencia' => 'x', 'items' => []], 201)]);

        $entrega = $this->entregaConItems([
            ['codigo' => 'DEMO-GOT-1', 'nombre' => 'TIMOLOL', 'cantidad_solicitada' => 2, 'cantidad_entregada' => 2, 'cantidad_pendiente' => 0, 'resultado' => 'entregado'],
            ['codigo' => 'DEMO-INY-1', 'nombre' => 'AGUJAS', 'cantidad_solicitada' => 3, 'cantidad_entregada' => 1, 'cantidad_pendiente' => 2, 'resultado' => 'parcial'],
            ['codigo' => 'DEMO-GOT-2', 'nombre' => 'LAGRIMAS', 'cantidad_solicitada' => 1, 'cantidad_entregada' => 0, 'cantidad_pendiente' => 1, 'resultado' => 'faltante'],
        ], Sede::factory()->create(['codigo' => 'LA30']));

        $entrega->recalcularEstado();
        $entrega->recalcularEstado();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer secreto')
            && $r['referencia'] === 'ENTREGA-'.$entrega->id
            && $r['sede'] === 'LA30'
            && $r['items'] === [
                ['codigo' => 'DEMO-GOT-1', 'presentaciones' => 2],
                ['codigo' => 'DEMO-INY-1', 'presentaciones' => 1],
            ]);
    }

    public function test_i7_sin_api_configurada_no_llama_a_nada(): void
    {
        Http::fake();

        $this->entregaConItems([
            ['codigo' => 'MED-001', 'nombre' => 'LOSARTAN', 'cantidad_solicitada' => 30, 'cantidad_entregada' => 30, 'cantidad_pendiente' => 0, 'resultado' => 'entregado'],
        ])->recalcularEstado();

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------ *
     *  I1–I9
     * ------------------------------------------------------------------ */

    public function test_i1_entrega_completa_envia_presentaciones_iguales_a_cantidad_entregada(): void
    {
        $this->configurarApi();
        Http::fake(['inventario.test/api/dispensaciones' => Http::response(['referencia' => 'ok', 'items' => []], 201)]);

        $entrega = $this->entregaConItems([
            [
                'codigo' => 'MX377-6',
                'nombre' => 'LOSARTAN 50 MG',
                'cantidad_solicitada' => 30,
                'cantidad_entregada' => 30,
                'cantidad_pendiente' => 0,
                'unidad' => 'TAB',
                'resultado' => EntregaItem::RESULTADO_ENTREGADO,
            ],
        ]);

        $entrega->recalcularEstado();

        Http::assertSent(fn (Request $r) => $r['referencia'] === 'ENTREGA-'.$entrega->id
            && $r['items'] === [['codigo' => 'MX377-6', 'presentaciones' => 30]]);
    }

    public function test_i2_entrega_parcial_solo_envia_lo_entregado(): void
    {
        $this->configurarApi();
        Http::fake(['inventario.test/api/dispensaciones' => Http::response(['referencia' => 'ok', 'items' => []], 201)]);

        $entrega = $this->entregaConItems([
            [
                'codigo' => 'MX377-6',
                'nombre' => 'LOSARTAN 50 MG',
                'cantidad_solicitada' => 30,
                'cantidad_entregada' => 15,
                'cantidad_pendiente' => 15,
                'unidad' => 'TAB',
                'resultado' => EntregaItem::RESULTADO_PARCIAL,
                'motivo' => 'Sin stock completo',
            ],
        ]);

        $entrega->recalcularEstado();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['items'] === [['codigo' => 'MX377-6', 'presentaciones' => 15]]);
    }

    public function test_i3_segunda_atencion_nueva_referencia_y_solo_cantidad_nueva(): void
    {
        $this->configurarApi();
        Http::fake(['inventario.test/api/dispensaciones' => Http::response(['referencia' => 'ok', 'items' => []], 201)]);

        $sede = Sede::factory()->create(['nombre' => 'Sede Centro', 'codigo' => 'CEN', 'activa' => true]);
        $usuario = User::factory()->administrador()->create(['sede_id' => $sede->id]);
        $this->actingAs($usuario);

        $ticket = $this->ticketListo($sede, $usuario, [
            ['codigo' => 'MX377-6', 'nombre' => 'LOSARTAN 50 MG', 'cantidad' => 30, 'unidad' => 'TAB'],
        ]);

        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);
        $itemId = $dto->items[0]->id;

        $primera = app(RegistrarEntrega::class)->handle($dto, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'sede_id' => $sede->id,
            'receptor_nombre' => 'A',
            'receptor_documento' => '1',
            'firma_contenido' => 'firma',
            'items' => [[
                'ticket_item_id' => $itemId,
                'codigo' => 'MX377-6',
                'nombre' => 'LOSARTAN 50 MG',
                'cantidad_solicitada' => 30,
                'unidad' => 'TAB',
                'cantidad_entregada' => 15,
                'resultado' => EntregaItem::RESULTADO_PARCIAL,
                'motivo' => 'Parcial',
            ]],
        ]);

        $dto2 = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);
        $segunda = app(RegistrarEntrega::class)->handle($dto2, $usuario, [
            'tipo' => Entrega::TIPO_PRESENCIAL,
            'sede_id' => $sede->id,
            'receptor_nombre' => 'A',
            'receptor_documento' => '1',
            'firma_contenido' => 'firma',
            'items' => [[
                'ticket_item_id' => $itemId,
                'codigo' => 'MX377-6',
                'nombre' => 'LOSARTAN 50 MG',
                'cantidad_solicitada' => 15,
                'unidad' => 'TAB',
                'cantidad_entregada' => 10,
                'resultado' => EntregaItem::RESULTADO_PARCIAL,
                'motivo' => 'Parcial',
            ]],
        ]);

        $this->assertNotSame($primera->id, $segunda->id);

        $payloads = [];
        Http::assertSent(function (Request $r) use (&$payloads): bool {
            $payloads[] = $r->data();

            return true;
        });

        $this->assertCount(2, $payloads);
        $this->assertSame('ENTREGA-'.$primera->id, $payloads[0]['referencia']);
        $this->assertSame([['codigo' => 'MX377-6', 'presentaciones' => 15]], $payloads[0]['items']);
        $this->assertSame('ENTREGA-'.$segunda->id, $payloads[1]['referencia']);
        $this->assertSame([['codigo' => 'MX377-6', 'presentaciones' => 10]], $payloads[1]['items']);

        $pendiente = app(SaldoTicket::class)->pendienteDeLinea($ticket->numero, (string) $itemId, 30);
        $this->assertSame(5.0, $pendiente);
    }

    public function test_i4_medicamento_con_cero_entregado_no_va_en_el_payload(): void
    {
        $this->configurarApi();
        Http::fake(['inventario.test/api/dispensaciones' => Http::response(['referencia' => 'ok', 'items' => []], 201)]);

        $entrega = $this->entregaConItems([
            ['codigo' => 'MED-OK', 'nombre' => 'A', 'cantidad_solicitada' => 10, 'cantidad_entregada' => 10, 'cantidad_pendiente' => 0, 'resultado' => 'entregado'],
            ['codigo' => 'MED-ZERO', 'nombre' => 'B', 'cantidad_solicitada' => 5, 'cantidad_entregada' => 0, 'cantidad_pendiente' => 5, 'resultado' => 'faltante', 'motivo' => 'Sin stock'],
        ]);

        $entrega->recalcularEstado();

        Http::assertSent(fn (Request $r) => $r['items'] === [['codigo' => 'MED-OK', 'presentaciones' => 10]]);
    }

    public function test_i5_payload_usa_codigo_de_sede_de_la_entrega(): void
    {
        $this->configurarApi();
        Http::fake(['inventario.test/api/dispensaciones' => Http::response(['referencia' => 'ok', 'items' => []], 201)]);

        $centro = Sede::factory()->create(['nombre' => 'Sede Centro', 'codigo' => 'CEN', 'activa' => true]);
        $entrega = $this->entregaConItems([
            ['codigo' => 'MED-001', 'nombre' => 'A', 'cantidad_solicitada' => 5, 'cantidad_entregada' => 5, 'cantidad_pendiente' => 0, 'resultado' => 'entregado'],
        ], $centro);

        $entrega->recalcularEstado();

        Http::assertSent(fn (Request $r) => $r['sede'] === 'CEN');
    }

    public function test_i6_reejecutar_el_job_reenvia_la_misma_referencia(): void
    {
        $this->configurarApi();
        Http::fake(['inventario.test/api/dispensaciones' => Http::response(['referencia' => 'ok', 'items' => []], 201)]);

        $entrega = $this->entregaConItems([
            ['codigo' => 'MED-001', 'nombre' => 'A', 'cantidad_solicitada' => 7, 'cantidad_entregada' => 7, 'cantidad_pendiente' => 0, 'resultado' => 'entregado'],
        ]);
        // Sin eventos: no queremos el dispatch del listener, solo las dos ejecuciones manuales.
        Entrega::withoutEvents(fn () => $entrega->update(['estado' => Entrega::ESTADO_COMPLETADA]));

        $job = new DescontarInventarioDeEntrega($entrega->fresh(['items', 'sede', 'usuario']));
        $job->handle(app(\App\Services\Inventario\InventarioApi::class));
        $job->handle(app(\App\Services\Inventario\InventarioApi::class));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r['referencia'] === 'ENTREGA-'.$entrega->id
            && $r['items'] === [['codigo' => 'MED-001', 'presentaciones' => 7]]);

        // La idempotencia de stock es responsabilidad de inventario-api, no de este fake.
        $this->assertSame(5, $job->tries);
        $this->assertSame([30, 120, 600, 1800], $job->backoff);
    }

    public function test_i8_fallo_http_no_borra_la_entrega_y_el_job_declara_reintentos(): void
    {
        $this->configurarApi();
        Http::fake(['inventario.test/api/dispensaciones' => Http::response(['message' => 'caído'], 503)]);

        $entrega = $this->entregaConItems([
            ['codigo' => 'MED-001', 'nombre' => 'A', 'cantidad_solicitada' => 3, 'cantidad_entregada' => 3, 'cantidad_pendiente' => 0, 'resultado' => 'entregado'],
        ]);
        Entrega::withoutEvents(fn () => $entrega->update(['estado' => Entrega::ESTADO_COMPLETADA]));
        $id = $entrega->id;

        $job = new DescontarInventarioDeEntrega($entrega->fresh(['items', 'sede', 'usuario']));

        try {
            $job->handle(app(\App\Services\Inventario\InventarioApi::class));
            $this->fail('Debió lanzar por HTTP 503.');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertDatabaseHas('entregas', [
            'id' => $id,
            'estado' => Entrega::ESTADO_COMPLETADA,
        ]);
        $this->assertSame(5, $job->tries);
    }

    public function test_i9_respuesta_con_faltante_deja_log_y_conserva_la_entrega(): void
    {
        $this->configurarApi();
        Http::fake(['inventario.test/api/dispensaciones' => Http::response([
            'referencia' => 'ENTREGA-1',
            'items' => [
                ['codigo' => 'MED-001', 'solicitado' => 10, 'entregado' => 7, 'faltante' => 3],
            ],
        ], 201)]);

        Log::spy();

        $entrega = $this->entregaConItems([
            ['codigo' => 'MED-001', 'nombre' => 'A', 'cantidad_solicitada' => 10, 'cantidad_entregada' => 10, 'cantidad_pendiente' => 0, 'resultado' => 'entregado'],
        ]);
        Entrega::withoutEvents(fn () => $entrega->update(['estado' => Entrega::ESTADO_COMPLETADA]));

        (new DescontarInventarioDeEntrega($entrega->fresh(['items', 'sede', 'usuario'])))
            ->handle(app(\App\Services\Inventario\InventarioApi::class));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mensaje, array $contexto): bool => str_contains($mensaje, 'más de lo que había')
                && $contexto['codigo'] === 'MED-001'
                && $contexto['faltante'] === 3)
            ->once();

        $this->assertDatabaseHas('entregas', ['id' => $entrega->id, 'estado' => Entrega::ESTADO_COMPLETADA]);
    }

    public function test_ceil_documenta_redondeo_hacia_arriba_de_fracciones(): void
    {
        $this->configurarApi();
        Http::fake(['inventario.test/api/dispensaciones' => Http::response(['referencia' => 'ok', 'items' => []], 201)]);

        $entrega = $this->entregaConItems([
            ['codigo' => 'MED-FRAC', 'nombre' => 'A', 'cantidad_solicitada' => 2, 'cantidad_entregada' => 1.2, 'cantidad_pendiente' => 0.8, 'resultado' => 'parcial', 'motivo' => 'x'],
        ]);
        Entrega::withoutEvents(fn () => $entrega->update(['estado' => Entrega::ESTADO_PARCIAL]));

        (new DescontarInventarioDeEntrega($entrega->fresh(['items', 'sede', 'usuario'])))
            ->handle(app(\App\Services\Inventario\InventarioApi::class));

        // Comportamiento actual: ceil(1.2) = 2. Riesgo si cantidad_entregada no es ya presentación entera.
        Http::assertSent(fn (Request $r) => $r['items'] === [['codigo' => 'MED-FRAC', 'presentaciones' => 2]]);
    }

    public function test_mismas_lineas_con_mismo_codigo_se_suman_en_una_presentacion(): void
    {
        $this->configurarApi();
        Http::fake(['inventario.test/api/dispensaciones' => Http::response(['referencia' => 'ok', 'items' => []], 201)]);

        $entrega = $this->entregaConItems([
            ['codigo' => 'MED-MISMO', 'nombre' => 'A caja', 'cantidad_solicitada' => 5, 'cantidad_entregada' => 5, 'cantidad_pendiente' => 0, 'resultado' => 'entregado'],
            ['codigo' => 'MED-MISMO', 'nombre' => 'A blister', 'cantidad_solicitada' => 3, 'cantidad_entregada' => 3, 'cantidad_pendiente' => 0, 'resultado' => 'entregado'],
        ]);
        Entrega::withoutEvents(fn () => $entrega->update(['estado' => Entrega::ESTADO_COMPLETADA]));

        (new DescontarInventarioDeEntrega($entrega->fresh(['items', 'sede', 'usuario'])))
            ->handle(app(\App\Services\Inventario\InventarioApi::class));

        Http::assertSent(fn (Request $r) => $r['items'] === [['codigo' => 'MED-MISMO', 'presentaciones' => 8]]);
    }

    /* ------------------------------------------------------------------ *
     *  Domicilios (documentan comportamiento actual, sin cambiarlo)
     * ------------------------------------------------------------------ */

    public function test_d1_domicilio_despacha_descuento_al_registrar_la_atencion(): void
    {
        $this->configurarApi();
        Queue::fake();

        $sede = Sede::factory()->create(['codigo' => 'CEN', 'activa' => true]);
        $usuario = User::factory()->administrador()->create(['sede_id' => $sede->id]);
        $this->actingAs($usuario);

        $ticket = $this->ticketListo($sede, $usuario, [
            ['codigo' => 'MED-001', 'nombre' => 'A', 'cantidad' => 10, 'unidad' => 'TAB'],
        ]);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        app(RegistrarEntrega::class)->handle($dto, $usuario, [
            'tipo' => Entrega::TIPO_DOMICILIO,
            'sede_id' => $sede->id,
            'items' => [[
                'ticket_item_id' => $dto->items[0]->id,
                'codigo' => 'MED-001',
                'nombre' => 'A',
                'cantidad_solicitada' => 10,
                'unidad' => 'TAB',
                'cantidad_entregada' => 10,
                'resultado' => EntregaItem::RESULTADO_ENTREGADO,
            ]],
        ]);

        Queue::assertPushed(DescontarInventarioDeEntrega::class, 1);
    }

    public function test_d2_confirmar_domina_entregado_no_vuelve_a_despachar_si_estado_no_cambia(): void
    {
        $this->configurarApi();
        Queue::fake();

        $sede = Sede::factory()->create(['codigo' => 'CEN', 'activa' => true]);
        $usuario = User::factory()->administrador()->create(['sede_id' => $sede->id]);
        $this->actingAs($usuario);

        $ticket = $this->ticketListo($sede, $usuario, [
            ['codigo' => 'MED-001', 'nombre' => 'A', 'cantidad' => 4, 'unidad' => 'TAB'],
        ]);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        $entrega = app(RegistrarEntrega::class)->handle($dto, $usuario, [
            'tipo' => Entrega::TIPO_DOMICILIO,
            'sede_id' => $sede->id,
            'items' => [[
                'ticket_item_id' => $dto->items[0]->id,
                'codigo' => 'MED-001',
                'nombre' => 'A',
                'cantidad_solicitada' => 4,
                'unidad' => 'TAB',
                'cantidad_entregada' => 4,
                'resultado' => EntregaItem::RESULTADO_ENTREGADO,
            ]],
        ]);

        Queue::assertPushed(DescontarInventarioDeEntrega::class, 1);

        $envio = $entrega->domicilioEnvio;
        $registrar = app(RegistrarEntrega::class);
        foreach ([
            DomicilioEnvio::ESTADO_PREPARADO,
            DomicilioEnvio::ESTADO_ENVIADO_DOMINA,
            DomicilioEnvio::ESTADO_EN_RUTA,
        ] as $estado) {
            $envio = $registrar->cambiarEstadoDomicilio($envio->fresh(), $estado, $usuario);
        }

        Queue::fake();
        $registrar->cambiarEstadoDomicilio($envio->fresh(), DomicilioEnvio::ESTADO_ENTREGADO, $usuario);

        // recalcularEstado deja la entrega en completada otra vez: wasChanged('estado') = false.
        Queue::assertNothingPushed();
    }

    public function test_d3_no_entregado_desde_completada_puede_volver_a_despachar_misma_entrega(): void
    {
        $this->configurarApi();
        Queue::fake();

        $sede = Sede::factory()->create(['codigo' => 'CEN', 'activa' => true]);
        $usuario = User::factory()->administrador()->create(['sede_id' => $sede->id]);
        $this->actingAs($usuario);

        $ticket = $this->ticketListo($sede, $usuario, [
            ['codigo' => 'MED-001', 'nombre' => 'A', 'cantidad' => 4, 'unidad' => 'TAB'],
        ]);
        $dto = app(TicketConsultaInterface::class)->buscarPorNumero($ticket->numero);

        $entrega = app(RegistrarEntrega::class)->handle($dto, $usuario, [
            'tipo' => Entrega::TIPO_DOMICILIO,
            'sede_id' => $sede->id,
            'items' => [[
                'ticket_item_id' => $dto->items[0]->id,
                'codigo' => 'MED-001',
                'nombre' => 'A',
                'cantidad_solicitada' => 4,
                'unidad' => 'TAB',
                'cantidad_entregada' => 4,
                'resultado' => EntregaItem::RESULTADO_ENTREGADO,
            ]],
        ]);

        $this->assertSame(Entrega::ESTADO_COMPLETADA, $entrega->fresh()->estado);

        $envio = $entrega->domicilioEnvio;
        $registrar = app(RegistrarEntrega::class);
        foreach ([
            DomicilioEnvio::ESTADO_PREPARADO,
            DomicilioEnvio::ESTADO_ENVIADO_DOMINA,
            DomicilioEnvio::ESTADO_EN_RUTA,
        ] as $estado) {
            $envio = $registrar->cambiarEstadoDomicilio($envio->fresh(), $estado, $usuario);
        }

        Queue::fake();
        $registrar->cambiarEstadoDomicilio(
            $envio->fresh(),
            DomicilioEnvio::ESTADO_NO_ENTREGADO,
            $usuario,
            nota: 'No había nadie',
        );

        // completada → parcial dispara otra vez el job con la misma Entrega (misma referencia ENTREGA-{id}).
        Queue::assertPushed(DescontarInventarioDeEntrega::class, function (DescontarInventarioDeEntrega $job) use ($entrega): bool {
            return (int) $job->entrega->getKey() === (int) $entrega->id;
        });
    }
}
