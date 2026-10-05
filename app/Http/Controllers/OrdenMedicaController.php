<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Soporte;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve la orden médica de un paciente desde el disco privado.
 *
 * Las órdenes son datos de salud: viven en `storage/app/private` y nunca
 * tienen URL pública. Llegar a una exige estar autenticado, tener el permiso
 * `orientacion.ver_orden` y queda registrado en la auditoría.
 */
class OrdenMedicaController extends Controller
{
    public function mostrar(Soporte $soporte): StreamedResponse
    {
        abort_unless(
            (bool) auth()->user()?->puede('orientacion.ver_orden'),
            403,
            'No tienes permiso para ver órdenes médicas.',
        );

        $disco = Storage::disk('local');

        abort_unless(
            filled($soporte->orden_medica) && $disco->exists($soporte->orden_medica),
            404,
            'La orden médica ya no está disponible.',
        );

        // Quién abrió la orden de qué paciente. Ni la ruta del archivo ni la
        // marca de alto costo entran al rastro: son datos de salud.
        Auditoria::registrar(
            accion: Auditoria::ACCION_DESCARGO_ORDEN,
            descripcion: 'Abrió la orden médica del paciente '
                .($soporte->paciente?->documento_completo ?? 'sin identificar'),
            entidadTipo: 'orden_medica',
            entidadId: $soporte->getKey(),
        );

        // Se muestra en el navegador; quien quiera guardarla usa el botón del visor.
        return $disco->response($soporte->orden_medica, $this->nombreDescarga($soporte), [
            'Content-Type' => $disco->mimeType($soporte->orden_medica) ?: 'application/octet-stream',
        ]);
    }

    /**
     * Un nombre que identifique la orden sin exponer la ruta interna.
     */
    private function nombreDescarga(Soporte $soporte): string
    {
        $documento = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $soporte->paciente?->numero_documento) ?: 'paciente';
        $extension = pathinfo($soporte->orden_medica, PATHINFO_EXTENSION) ?: 'bin';
        $fecha = $soporte->created_at?->format('Ymd') ?? 'sinfecha';

        return "orden-medica-{$documento}-{$fecha}.{$extension}";
    }
}
