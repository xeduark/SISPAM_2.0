<?php

namespace App\Services\Ticket;

use App\Contracts\Ticket\Dto\PacienteResumenDto;
use App\Contracts\Ticket\Dto\TicketDto;
use App\Contracts\Ticket\Dto\TicketItemDto;
use App\Contracts\Ticket\TicketConsultaInterface;
use App\Models\Ticket;

/**
 * Los tickets de verdad, para el módulo de entrega.
 *
 * Reemplaza a `TicketConsultaMock`. El contrato no cambió: sigue siendo
 * `buscarPorNumero($numero)`, porque el número del ticket lleva dentro la
 * sede y la fecha y es único en todo el sistema.
 */
class TicketConsultaDb implements TicketConsultaInterface
{
    public function buscarPorNumero(string $numero): ?TicketDto
    {
        $numero = trim($numero);

        if ($numero === '') {
            return null;
        }

        $ticket = Ticket::query()
            ->with(['paciente', 'items'])
            ->where('numero', $numero)
            ->first()
            ?? $this->porTurnoDeHoy($numero);

        return $ticket === null ? null : $this->aDto($ticket);
    }

    /**
     * En el mostrador el paciente muestra el turno corto («A-023»), no el
     * número largo. El contrato deja esa puerta abierta, así que si lo que
     * escribieron no es un número se busca como turno de hoy en la sede de
     * quien atiende: fuera de ese día y esa sede, el turno se repite.
     */
    private function porTurnoDeHoy(string $turno): ?Ticket
    {
        $sedeId = auth()->user()?->sede_id;

        if ($sedeId === null) {
            return null;
        }

        return Ticket::query()
            ->with(['paciente', 'items'])
            ->where('sede_id', $sedeId)
            ->whereDate('fecha', today())
            ->where('turno', strtoupper($turno))
            ->latest('id')
            ->first();
    }

    private function aDto(Ticket $ticket): TicketDto
    {
        $paciente = $ticket->paciente;

        return new TicketDto(
            numero: $ticket->numero,
            estado: $ticket->estado,
            sedeId: (int) $ticket->sede_id,
            paciente: new PacienteResumenDto(
                id: $paciente?->getKey(),
                tipoDocumento: (string) $paciente?->tipo_documento,
                numeroDocumento: (string) $paciente?->numero_documento,
                nombreCompleto: (string) $paciente?->nombre_completo,
                telefonoMovil: $paciente?->telefono_movil ?? $paciente?->telefono,
                direccion: $paciente?->direccion,
                barrio: $paciente?->barrio,
                ciudad: $paciente?->ciudad_residencia,
                indicacionesEntrega: $paciente?->indicaciones_entrega,
                contactoConfirmado: (bool) $paciente?->tieneContactoConfirmado(),
            ),
            items: $ticket->items->map(fn ($item): TicketItemDto => new TicketItemDto(
                id: (string) $item->getKey(),
                codigo: $item->codigo,
                nombre: $item->nombre,
                cantidad: (float) $item->cantidad,
                unidad: $item->unidad,
            ))->all(),
            altoCosto: (bool) $ticket->alto_costo,
            turno: $ticket->turno,
        );
    }
}
