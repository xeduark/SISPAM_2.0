<?php

namespace App\Contracts\Ticket;

use App\Contracts\Ticket\Dto\TicketDto;

/**
 * Puerto de consulta del módulo de ticket/fórmula.
 * Entrega solo consume; no crea ni edita tickets.
 */
interface TicketConsultaInterface
{
    /**
     * Busca un ticket por su número (o turno, según lo defina el módulo de ticket).
     */
    public function buscarPorNumero(string $numero): ?TicketDto;
}
