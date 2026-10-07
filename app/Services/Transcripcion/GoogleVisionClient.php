<?php

namespace App\Services\Transcripcion;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lee el texto de una fórmula con Google Cloud Vision (DOCUMENT_TEXT_DETECTION).
 *
 * La clave va en el encabezado y no en la URL, para que no termine en un log
 * si la petición falla.
 */
class GoogleVisionClient
{
    private const URL = 'https://vision.googleapis.com/v1/';

    public function leerTexto(string $contenido, string $mime): string
    {
        $clave = config('services.google_vision.key');

        if (blank($clave)) {
            throw new RuntimeException('Falta configurar GOOGLE_VISION_API_KEY.');
        }

        $esPdf = $mime === 'application/pdf';
        $caracteristicas = [['type' => 'DOCUMENT_TEXT_DETECTION']];

        // Sin `pages`, un PDF se lee hasta su página 5 (límite síncrono de Vision).
        $peticion = $esPdf
            ? ['inputConfig' => ['content' => base64_encode($contenido), 'mimeType' => 'application/pdf'], 'features' => $caracteristicas]
            : ['image' => ['content' => base64_encode($contenido)], 'features' => $caracteristicas, 'imageContext' => ['languageHints' => ['es']]];

        $respuesta = Http::timeout(60)
            ->withHeaders(['X-Goog-Api-Key' => $clave])
            ->post(self::URL.($esPdf ? 'files:annotate' : 'images:annotate'), ['requests' => [$peticion]]);

        if ($respuesta->failed()) {
            throw new RuntimeException('Google Vision respondió HTTP '.$respuesta->status().': '.$respuesta->json('error.message', ''));
        }

        $resultado = $respuesta->json('responses.0', []);

        if ($error = data_get($resultado, 'error.message')) {
            throw new RuntimeException("Google Vision: {$error}");
        }

        // Un PDF responde una lista de páginas; una imagen, una sola.
        $paginas = $esPdf ? ($resultado['responses'] ?? []) : [$resultado];

        return trim(collect($paginas)->pluck('fullTextAnnotation.text')->filter()->implode("\n"));
    }
}
