<?php

namespace App\Contracts\Domina;

/**
 * Estado reportado por Dómina (o por el mock interno).
 * Sin estructura de API inventada: solo lo mínimo para sincronizar.
 */
readonly class DominaEstadoDto
{
    public function __construct(
        public string $referencia,
        public string $estado,
        public ?string $detalle = null,
    ) {}
}
