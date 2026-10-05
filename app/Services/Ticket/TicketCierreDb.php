<?php

namespace App\Services\Ticket;

use App\Contracts\Ticket\TicketCierreInterface;
use App\Models\Ticket;

/**
 * Pone al día el estado del ticket cuando entrega registra una atención.
 */
class TicketCierreDb implements TicketCierreInterface
{
    public function cerrarPorEntrega(string $numero, bool $completa): void
    {
        $ticket = Ticket::where('numero', trim($numero))->first();

        // El número puede no ser de este módulo (o venir de datos de prueba):
        // el cierre nunca debe tumbar una entrega ya hecha al paciente.
        if ($ticket === null || $ticket->estaCerrado()) {
            return;
        }

        $ticket->update([
            'estado' => $completa ? Ticket::ESTADO_ENTREGADO : Ticket::ESTADO_PARCIAL,
            // El turno sale de la sala: ya pasó por la ventanilla. Un parcial
            // sigue siendo entregable por los faltantes, pero no vuelve a la
            // espera de hoy, porque el paciente ya fue atendido.
            'estado_sala' => Ticket::SALA_ATENDIDO,
            'cerrado_en' => $completa ? now() : null,
        ]);
    }
}
