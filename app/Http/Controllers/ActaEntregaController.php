<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Entrega;
use App\Models\Ticket;
use App\Models\Transcripcion;
use App\Services\Inventario\InventarioApi;
use App\Services\Transcripcion\OrdenDeEntrega;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * El acta de una entrega: lo que recibió el paciente (con lote y vencimiento),
 * lo que quedó pendiente, las fórmulas que no se dispensan con su motivo, y la
 * firma de quien recibió. Es lo que el paciente firma; queda en la auditoría.
 */
class ActaEntregaController extends Controller
{
    public function mostrar(Entrega $entrega, OrdenDeEntrega $orden, InventarioApi $inventario): View
    {
        $usuario = auth()->user();

        abort_unless($usuario?->puede('entrega.ver') || $usuario?->puede('transcripcion.ver'), 403);
        abort_unless($usuario->es_administrador || $usuario->sede_id === $entrega->sede_id, 403);

        Auditoria::registrar(
            accion: Auditoria::ACCION_ABRIO_ACTA_ENTREGA,
            descripcion: "Abrió el acta de la entrega #{$entrega->id} (ticket {$entrega->ticket_numero})",
            entidadTipo: 'entrega',
            entidadId: $entrega->getKey(),
        );

        $entrega->load(['items', 'paciente', 'sede', 'usuario', 'domicilioEnvio']);
        $ticket = Ticket::where('numero', $entrega->ticket_numero)->first();

        // De qué lote salió cada medicamento. Si el inventario no responde, el acta sale igual sin lotes.
        $lotes = collect();
        if (InventarioApi::configurada()) {
            try {
                $lotes = collect($inventario->movimientosDe("ENTREGA-{$entrega->id}"))->groupBy('codigo');
            } catch (Throwable) {
                $lotes = null;
            }
        }

        $noDispensados = $ticket
            ? $orden->noDispensados($orden->transcripciones($ticket)->where('estado', Transcripcion::ESTADO_CONFIRMADA))
            : collect();

        // La firma vive en el disco privado: se incrusta en el acta, nunca con una URL pública.
        $firma = $entrega->firma_path && Storage::disk('local')->exists($entrega->firma_path)
            ? 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($entrega->firma_path))
            : null;

        return view('actas.entrega', compact('entrega', 'ticket', 'lotes', 'noDispensados', 'firma'));
    }
}
