<?php

namespace App\Services\Inventario;

use App\Contracts\Inventario\CatalogoInventarioInterface;
use App\Contracts\Inventario\Coincidencia;
use App\Services\Transcripcion\InterpretarFormula;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de la API de inventario (proyecto aparte: C:\laragon\www\inventario-api).
 * Se usa cuando INVENTARIO_API_URL está en el .env; si no, sigue el mock.
 */
class InventarioApi implements CatalogoInventarioInterface
{
    public static function configurada(): bool
    {
        return filled(config('services.inventario.url')) && filled(config('services.inventario.token'));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim(config('services.inventario.url'), '/').'/api')
            ->withToken(config('services.inventario.token'))
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * La API filtra por palabras; el orden por similitud se hace aquí, igual que el mock.
     * Se busca con la primera palabra de lo prescrito (el principio activo) para no
     * perder coincidencias por la concentración o la forma escritas distinto.
     */
    public function buscar(string $prescrito, int $limite = 5, ?string $sede = null): array
    {
        $palabra = strtok(InterpretarFormula::normalizar($prescrito), ' ') ?: $prescrito;

        return collect($this->http()->get('productos', array_filter(['buscar' => $palabra, 'sede' => $sede, 'limite' => 50]))->throw()->json())
            ->map(function (array $p) use ($prescrito) {
                $nombre = trim(implode(' ', array_filter([$p['nombre_generico'], $p['concentracion'], $p['forma_farmaceutica'], $p['presentacion_comercial']])));

                return new Coincidencia(
                    codigo: $p['codigo'],
                    agrupador: (string) $p['codigo_agrupador'],
                    nombre: $nombre,
                    similitud: InterpretarFormula::similitud($prescrito, $nombre),
                    stock: $p['stock'] ?? null,
                );
            })
            ->sortByDesc(fn (Coincidencia $c) => $c->similitud - (InterpretarFormula::mismaConcentracion($prescrito, $c->nombre) ? 0 : 20))
            ->take($limite)
            ->values()
            ->all();
    }

    /**
     * Stock dispensable hoy en la sede, por código (en presentaciones).
     * ponytail: una llamada por código; si las órdenes traen muchos productos, un endpoint por lote de códigos.
     *
     * @param  list<string>  $codigos
     * @return array<string, int>
     */
    public function stock(array $codigos, string $sede): array
    {
        $stock = [];
        foreach (array_unique($codigos) as $codigo) {
            $stock[$codigo] = (int) ($this->producto($codigo, $sede)['stock_dispensable'] ?? 0);
        }

        return $stock;
    }

    /**
     * Un producto por SKU con sus lotes con stock y su kardex reciente (GET /api/productos/{sku}).
     * null si el SKU no existe en el inventario.
     *
     * @return array{producto: array<string, mixed>, stock_dispensable: int, lotes: list<array<string, mixed>>, movimientos: list<array<string, mixed>>}|null
     */
    public function producto(string $sku, ?string $sede = null): ?array
    {
        $respuesta = $this->http()->get('productos/'.rawurlencode($sku), array_filter(['sede' => $sede]));

        return $respuesta->notFound() ? null : $respuesta->throw()->json();
    }

    /**
     * Los movimientos de una referencia exacta (ENTREGA-12): de qué lote salió cada cosa.
     *
     * @return list<array{codigo: string, numero_lote: string, fecha_vencimiento: string, cantidad: int}>
     */
    public function movimientosDe(string $referencia): array
    {
        return $this->http()->get('movimientos', ['referencia' => $referencia])->throw()->json('data', []);
    }

    /**
     * Descuenta del inventario. La referencia es única: repetirla no descuenta dos veces.
     *
     * @param  list<array{codigo: string, presentaciones: int}>  $items
     * @return array{referencia: string, items: list<array{codigo: string, solicitado: int, entregado: int, faltante: int}>}
     */
    public function dispensar(string $referencia, string $sede, array $items, ?string $usuario = null): array
    {
        return $this->http()
            ->post('dispensaciones', compact('referencia', 'sede', 'items', 'usuario'))
            ->throw()
            ->json();
    }
}
