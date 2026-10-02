<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Farmacia captura los medicamentos del ticket y lo deja listo para entrega.
 */
class AlistarTicket
{
    /**
     * @param  list<array{codigo: string, nombre: string, cantidad: float|int, unidad?: string, observacion?: ?string}>  $items
     */
    public function handle(Ticket $ticket, User $usuario, array $items): Ticket
    {
        if (! $ticket->sePuedeAlistar()) {
            throw new InvalidArgumentException(
                "El ticket {$ticket->numero} está «{$ticket->estado_etiqueta}» y ya no se puede alistar.",
            );
        }

        if ($items === []) {
            throw new InvalidArgumentException('Agrega al menos un medicamento para dejar el ticket listo.');
        }

        return DB::transaction(function () use ($ticket, $usuario, $items): Ticket {
            // Alistar de nuevo reemplaza lo capturado antes: lo que vale es
            // la última revisión de farmacia.
            $ticket->items()->delete();

            foreach ($items as $item) {
                $ticket->items()->create([
                    'codigo' => trim((string) $item['codigo']),
                    'nombre' => trim((string) $item['nombre']),
                    'cantidad' => (float) $item['cantidad'],
                    'unidad' => trim((string) ($item['unidad'] ?? 'UND')) ?: 'UND',
                    'observacion' => $item['observacion'] ?? null,
                ]);
            }

            $ticket->update([
                'estado' => Ticket::ESTADO_LISTO,
                'alistado_por' => $usuario->getKey(),
                'alistado_en' => now(),
            ]);

            return $ticket->fresh(['items']);
        });
    }

    /**
     * Anula un ticket que ya no se va a atender.
     */
    public function anular(Ticket $ticket, User $usuario, string $motivo): Ticket
    {
        if ($ticket->estaCerrado()) {
            throw new InvalidArgumentException(
                "El ticket {$ticket->numero} ya está «{$ticket->estado_etiqueta}».",
            );
        }

        $ticket->update([
            'estado' => Ticket::ESTADO_ANULADO,
            'motivo_anulacion' => trim($motivo),
            'cerrado_en' => now(),
        ]);

        return $ticket;
    }
}
