<?php

namespace App\Jobs;

use App\Models\Transcripcion;
use App\Services\Transcripcion\GeminiClient;
use App\Services\Transcripcion\GoogleVisionClient;
use App\Services\Transcripcion\InterpretarFormula;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lee una orden médica y deja la propuesta lista para que la transcriptora la
 * revise. Motor según TRANSCRIPCION_MOTOR: `gemini` (el del sistema nativo,
 * devuelve la fórmula ordenada) o `vision` (Google Vision, texto suelto).
 *
 * Si falla, queda `fallida` y se reintenta a mano desde la pantalla: un
 * reintento automático cobraría la lectura varias veces.
 */
class LeerFormula implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public Transcripcion $transcripcion,
    ) {}

    public function handle(InterpretarFormula $interpretar): void
    {
        $transcripcion = $this->transcripcion;
        $ruta = (string) $transcripcion->soporte?->orden_medica;
        $motor = config('services.transcripcion.motor');

        try {
            $disco = Storage::disk('local');

            if ($ruta === '' || ! $disco->exists($ruta)) {
                throw new \RuntimeException('El archivo de la orden médica no está disponible.');
            }

            [$contenido, $mime] = [(string) $disco->get($ruta), (string) $disco->mimeType($ruta)];

            if ($motor === 'gemini') {
                $lectura = app(GeminiClient::class)->leerFormula($contenido, $mime);
                $texto = json_encode($lectura, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $items = $interpretar->medicamentosDeLectura($lectura);
                $formula = [
                    'formulas' => array_values($lectura['formulas'] ?? []) ?: [['numero' => 1]],
                    'calidad_lectura' => $lectura['calidad_lectura'] ?? null,
                ];
            } else {
                $texto = app(GoogleVisionClient::class)->leerTexto($contenido, $mime);
                $items = $interpretar->medicamentos($texto);
                // Vision no separa fórmulas: queda una vacía para que la transcriptora la llene.
                $formula = ['formulas' => [['numero' => 1]]];
            }
        } catch (Throwable $e) {
            // Solo el id y el motivo: nunca el contenido de la fórmula.
            Log::warning('Transcripción: falló la lectura', ['transcripcion_id' => $transcripcion->getKey(), 'motor' => $motor, 'error' => $e->getMessage()]);
            $transcripcion->update(['estado' => Transcripcion::ESTADO_FALLIDA, 'error' => Str::limit($e->getMessage(), 250)]);

            return;
        }

        DB::transaction(function () use ($transcripcion, $texto, $items, $formula, $interpretar): void {
            $transcripcion->items()->delete();
            $transcripcion->items()->createMany($items);

            $transcripcion->update([
                'estado' => Transcripcion::ESTADO_LEIDA,
                'texto_ocr' => $texto,
                'formula' => $formula,
                'verificacion_cedula' => $interpretar->verificarCedula($texto, (string) $transcripcion->paciente?->numero_documento),
                'error' => null,
            ]);
        });
    }
}
