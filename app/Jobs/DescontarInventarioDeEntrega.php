<?php

namespace App\Jobs;

use App\Models\Entrega;
use App\Services\Inventario\InventarioApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Lo que se entregó sale del inventario. Va en cola y después del commit: si
 * la API no responde, la entrega al paciente ya quedó y el job se reintenta.
 * Reintentar es seguro: la referencia ENTREGA-{id} no se descuenta dos veces.
 *
 * Las cantidades de la entrega se toman como presentaciones (lo que se pasa por
 * la ventanilla: tableta, frasco, caja). El cálculo de gotas → frascos se hace
 * antes, al transcribir.
 */
class DescontarInventarioDeEntrega implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        public Entrega $entrega,
    ) {}

    public function handle(InventarioApi $inventario): void
    {
        $items = $this->entrega->items()
            ->where('cantidad_entregada', '>', 0)
            ->get()
            ->groupBy('codigo')
            ->map(fn ($lineas, $codigo) => ['codigo' => (string) $codigo, 'presentaciones' => (int) ceil($lineas->sum('cantidad_entregada'))])
            ->values()
            ->all();

        if ($items === []) {
            return;
        }

        $respuesta = $inventario->dispensar(
            referencia: 'ENTREGA-'.$this->entrega->getKey(),
            sede: (string) $this->entrega->sede?->codigo,
            items: $items,
            usuario: $this->entrega->usuario?->nombre,
        );

        // Se entregó algo que el inventario no tenía: el stock está desfasado. Solo códigos y cantidades.
        // ponytail: solo log; cuando entrega muestre el stock antes de entregar, esto casi no debería pasar.
        foreach ($respuesta['items'] ?? [] as $item) {
            if ($item['faltante'] > 0) {
                Log::warning('Inventario: se entregó más de lo que había en bodega', [
                    'entrega_id' => $this->entrega->getKey(),
                    'codigo' => $item['codigo'],
                    'faltante' => $item['faltante'],
                ]);
            }
        }
    }
}
