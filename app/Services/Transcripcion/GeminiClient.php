<?php

namespace App\Services\Transcripcion;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lee una fórmula con Gemini, el motor que usa el sistema nativo
 * (AIExtractorService::ejecutarLlamadaGenerativa). A diferencia de Vision, no
 * devuelve texto suelto sino la fórmula ya ordenada en JSON.
 *
 * Cambios frente al nativo: la clave va en el encabezado y no en la URL, se
 * verifica el certificado SSL, y si falla NO se inventa una lectura (el nativo
 * caía a un «extractor simulado» que devolvía medicamentos de ejemplo).
 */
class GeminiClient
{
    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const PROMPT = <<<'TXT'
        Eres un regente de farmacia en Colombia. Transcribe EXACTAMENTE lo que dice esta fórmula médica.
        No inventes: si un dato no está o no se lee, usa null. No corrijas nombres de medicamentos.

        Reglas:
        1. Si la imagen trae más de una fórmula (otra IPS, otro médico u otra fecha), cada una va aparte en "formulas"
           y cada medicamento dice a cuál pertenece en "formula" (1, 2…). Nunca mezcles medicamentos de fórmulas distintas.
        2. "cantidad_total" es el número TOTAL de la columna CANT/CANTIDAD de la fórmula (para todo el tratamiento),
           no la dosis por toma. Ej.: 1 tableta diaria por 3 meses → 90.
        3. "posologia" incluye unidades por toma, vía y frecuencia. Ej.: "1 tableta vía oral cada 12 horas".
        4. "duracion_dias" en días: 3 meses → 90.
        5. Fechas en formato AAAA-MM-DD.

        Responde SOLO este JSON:
        {
          "paciente": {"tipo_documento": "CC", "numero_documento": "", "nombre_completo": ""},
          "formulas": [{
            "numero": 1,
            "ips": "", "fecha_expedicion": "AAAA-MM-DD", "vigencia": "AAAA-MM-DD",
            "medico": {"nombre": "", "registro_medico": "", "especialidad": ""},
            "cie10_principal": {"codigo": "", "descripcion": ""},
            "cie10_relacionados": [{"codigo": "", "descripcion": ""}],
            "mipres": null, "autorizacion": null
          }],
          "medicamentos": [{
            "formula": 1, "descripcion": "nombre y concentración tal como está escrito",
            "concentracion": "", "forma_farmaceutica": "", "posologia": "",
            "duracion_dias": 90, "cantidad_total": 90
          }],
          "calidad_lectura": "ALTA | MEDIA | BAJA"
        }
        TXT;

    /** @return array<string, mixed> */
    public function leerFormula(string $contenido, string $mime): array
    {
        $clave = config('services.gemini.key');

        if (blank($clave)) {
            throw new RuntimeException('Falta configurar GEMINI_API_KEY.');
        }

        $respuesta = Http::timeout(90)
            ->withHeaders(['x-goog-api-key' => $clave])
            ->post(self::URL.config('services.gemini.modelo').':generateContent', [
                'contents' => [['parts' => [
                    ['text' => self::PROMPT],
                    ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($contenido)]],
                ]]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.1],
            ]);

        if ($respuesta->failed()) {
            throw new RuntimeException('Gemini respondió HTTP '.$respuesta->status().': '.$respuesta->json('error.message', ''));
        }

        $datos = json_decode((string) $respuesta->json('candidates.0.content.parts.0.text'), true);

        if (! is_array($datos) || ! isset($datos['medicamentos'])) {
            throw new RuntimeException('Gemini no devolvió una fórmula legible.');
        }

        return $datos;
    }
}
