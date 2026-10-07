<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Ticket;
use App\Services\Transcripcion\OrdenDeEntrega;
use Illuminate\Contracts\View\View;

/**
 * La orden de dispensación imprimible de un ticket. Trae datos de salud:
 * pide permiso y queda en la auditoría, igual que la orden médica.
 */
class OrdenEntregaController extends Controller
{
    public function mostrar(Ticket $ticket, OrdenDeEntrega $orden): View
    {
        $usuario = auth()->user();

        abort_unless($usuario?->puede('transcripcion.ver') || $usuario?->puede('entrega.ver'), 403);
        // Fuera de su sede solo el administrador, como en el resto del panel.
        abort_unless($usuario->es_administrador || $usuario->sede_id === $ticket->sede_id, 403);

        Auditoria::registrar(
            accion: Auditoria::ACCION_IMPRIMIO_ORDEN_ENTREGA,
            descripcion: "Abrió la orden de entrega del ticket {$ticket->numero}",
            entidadTipo: 'ticket',
            entidadId: $ticket->getKey(),
        );

        return view('ordenes.entrega', $orden->paraTicket($ticket->load(['paciente', 'sede', 'ventanilla', 'creadoPor'])));
    }
}
