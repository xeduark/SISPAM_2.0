<?php

namespace App\Contracts\Ticket\Dto;

/**
 * Ticket listo para el módulo de entrega (lo produce el módulo de ticket/fórmula).
 *
 * @param  list<TicketItemDto>  $items
 */
readonly class TicketDto
{
    /**
     * @param  list<TicketItemDto>  $items
     */
    public function __construct(
        public string $numero,
        public string $estado,
        public int $sedeId,
        public PacienteResumenDto $paciente,
        public array $items,
        public bool $altoCosto = false,
        public ?string $turno = null,
    ) {}

    public function listoParaEntrega(): bool
    {
        return in_array($this->estado, ['listo', 'parcial'], true);
    }
}
