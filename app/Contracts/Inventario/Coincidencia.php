<?php

namespace App\Contracts\Inventario;

/**
 * Un producto del inventario que se parece a lo prescrito.
 * Stock y lote quedan en null hasta tener inventario real.
 */
final readonly class Coincidencia
{
    public function __construct(
        public string $codigo,
        public string $agrupador,
        public string $nombre,
        public int $similitud,
        public ?int $stock = null,
        public ?string $lote = null,
    ) {}
}
