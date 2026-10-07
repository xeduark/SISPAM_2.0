<?php

namespace App\Contracts\Ticket;

/**
 * Puerto de cierre del módulo de ticket.
 *
 * Es un puerto **aparte** de `TicketConsultaInterface` a propósito: entrega
 * sigue consultando igual que siempre y su código no se entera de esto. Lo
 * usa el núcleo para poner al día el estado del ticket cuando el módulo de
 * entrega registra una atención.
 */
interface TicketCierreInterface
{
    /**
     * El ticket se atendió: queda entregado si se llevó todo, o parcial si
     * quedaron faltantes o pendientes.
     *
     * No revienta si el número no existe: el cierre nunca puede tumbar una
     * entrega que ya se le hizo al paciente.
     */
    public function cerrarPorEntrega(string $numero, bool $completa): void;
}
