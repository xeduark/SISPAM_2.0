<?php

namespace App\Listeners;

use App\Contracts\Ticket\TicketCierreInterface;
use App\Models\Entrega;

/**
 * Cuando el módulo de entrega guarda una atención, el ticket se pone al día.
 *
 * Va por el evento del modelo y no dentro de `RegistrarEntrega` para no tocar
 * el código del módulo de entrega: los dos módulos se hablan por el puerto
 * `TicketCierreInterface` y nada más.
 */
class SincronizarEstadoDelTicket
{
    public function __construct(
        private TicketCierreInterface $cierre,
    ) {}

    public function handle(Entrega $entrega): void
    {
        if (blank($entrega->ticket_numero)) {
            return;
        }

        $cerrado = match ($entrega->estado) {
            Entrega::ESTADO_COMPLETADA => true,
            Entrega::ESTADO_PARCIAL => false,
            // En proceso o anulada no cambian el ticket.
            default => null,
        };

        if ($cerrado === null) {
            return;
        }

        $this->cierre->cerrarPorEntrega($entrega->ticket_numero, $cerrado);
    }
}
