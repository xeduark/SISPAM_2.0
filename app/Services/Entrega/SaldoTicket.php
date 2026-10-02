<?php

namespace App\Services\Entrega;

use App\Contracts\Ticket\Dto\TicketDto;
use App\Contracts\Ticket\Dto\TicketItemDto;
use App\Models\Entrega;
use App\Models\EntregaItem;
use Illuminate\Support\Collection;

/**
 * Calcula cuánto queda por dispensar de un ticket según entregas previas.
 */
class SaldoTicket
{
    /**
     * Cantidad ya entregada por línea de ticket (suma de todas las atenciones).
     *
     * @return array<string, float> ticket_item_id => cantidad entregada acumulada
     */
    public function entregadoAcumulado(string $ticketNumero): array
    {
        return EntregaItem::query()
            ->whereHas('entrega', fn ($q) => $q
                ->where('ticket_numero', $ticketNumero)
                ->where('estado', '!=', Entrega::ESTADO_ANULADA))
            ->get()
            ->groupBy(fn (EntregaItem $item) => (string) $item->ticket_item_id)
            ->map(fn (Collection $items): float => (float) $items->sum('cantidad_entregada'))
            ->all();
    }

    /**
     * Ítems del ticket con la cantidad que todavía se puede dispensar.
     *
     * @return list<array{item: TicketItemDto, pendiente: float, entregado_acumulado: float}>
     */
    public function lineasConSaldo(TicketDto $ticket): array
    {
        $acumulado = $this->entregadoAcumulado($ticket->numero);
        $lineas = [];

        foreach ($ticket->items as $item) {
            $ya = (float) ($acumulado[$item->id] ?? 0);
            $pendiente = max(0, round((float) $item->cantidad - $ya, 2));
            $lineas[] = [
                'item' => $item,
                'pendiente' => $pendiente,
                'entregado_acumulado' => $ya,
            ];
        }

        return $lineas;
    }

    public function ticketCompletamenteDispensado(TicketDto $ticket): bool
    {
        $lineas = $this->lineasConSaldo($ticket);

        if ($lineas === []) {
            return false;
        }

        foreach ($lineas as $linea) {
            if ($linea['pendiente'] > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Solo líneas con cantidad pendiente > 0.
     *
     * @return list<array{item: TicketItemDto, pendiente: float, entregado_acumulado: float}>
     */
    public function lineasPendientes(TicketDto $ticket): array
    {
        return array_values(array_filter(
            $this->lineasConSaldo($ticket),
            fn (array $linea): bool => $linea['pendiente'] > 0,
        ));
    }

    public function pendienteDeLinea(string $ticketNumero, string $ticketItemId, float $cantidadOriginalTicket): float
    {
        $acumulado = $this->entregadoAcumulado($ticketNumero);
        $ya = (float) ($acumulado[$ticketItemId] ?? 0);

        return max(0, round($cantidadOriginalTicket - $ya, 2));
    }
}
