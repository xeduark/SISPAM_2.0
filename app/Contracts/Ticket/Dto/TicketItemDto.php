<?php

namespace App\Contracts\Ticket\Dto;

/**
 * Línea de medicamento de un ticket, lista para dispensar.
 */
readonly class TicketItemDto
{
    public function __construct(
        public string $id,
        public string $codigo,
        public string $nombre,
        public float $cantidad,
        public string $unidad = 'UND',
    ) {}
}
