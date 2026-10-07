<?php

namespace Tests\Feature\Entrega;

use App\Models\Entrega;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Cuando una entrega se cierra, lo entregado se descuenta en la API de inventario. Ninguna prueba toca la API real. */
class DescontarInventarioTest extends TestCase
{
    use RefreshDatabase;

    private function entrega(): Entrega
    {
        $entrega = Entrega::create([
            'ticket_numero' => 'TK-PRUEBA',
            'sede_id' => Sede::factory()->create(['codigo' => 'LA30'])->id,
            'usuario_id' => User::factory()->create()->id,
            'tipo' => Entrega::TIPO_PRESENCIAL,
        ]);
        $entrega->items()->createMany([
            ['codigo' => 'DEMO-GOT-1', 'nombre' => 'TIMOLOL', 'cantidad_solicitada' => 2, 'cantidad_entregada' => 2, 'resultado' => 'entregado'],
            ['codigo' => 'DEMO-INY-1', 'nombre' => 'AGUJAS', 'cantidad_solicitada' => 3, 'cantidad_entregada' => 1, 'resultado' => 'parcial'],
            ['codigo' => 'DEMO-GOT-2', 'nombre' => 'LAGRIMAS', 'cantidad_solicitada' => 1, 'cantidad_entregada' => 0, 'resultado' => 'faltante'],
        ]);

        return $entrega;
    }

    public function test_al_cerrar_la_entrega_descuenta_lo_entregado(): void
    {
        config(['services.inventario.url' => 'http://inventario.test', 'services.inventario.token' => 'secreto']);
        Http::fake(['inventario.test/api/dispensaciones' => Http::response(['referencia' => 'x', 'items' => []], 201)]);

        $entrega = $this->entrega();
        $entrega->recalcularEstado();
        $entrega->recalcularEstado(); // guardar otra vez sin cambiar estado no vuelve a mandar

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer secreto')
            && $r['referencia'] === 'ENTREGA-'.$entrega->id
            && $r['sede'] === 'LA30'
            && $r['items'] === [
                ['codigo' => 'DEMO-GOT-1', 'presentaciones' => 2],
                ['codigo' => 'DEMO-INY-1', 'presentaciones' => 1],
            ]);
    }

    public function test_sin_api_configurada_no_llama_a_nada(): void
    {
        Http::fake();

        $this->entrega()->recalcularEstado();

        Http::assertNothingSent();
    }
}
