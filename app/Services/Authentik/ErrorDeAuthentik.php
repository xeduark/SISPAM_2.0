<?php

namespace App\Services\Authentik;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Authentik no hizo lo que SISPAM le pidió.
 *
 * El mensaje va en español y se le puede mostrar a quien está creando el
 * usuario: dice qué revisar y nunca lleva el token.
 */
class ErrorDeAuthentik extends RuntimeException
{
    public static function desdeRespuesta(Response $respuesta): self
    {
        return new self(match (true) {
            $respuesta->status() === 401 => 'Authentik no aceptó el token de SISPAM (AUTHENTIK_API_TOKEN): no existe, venció o no es de tipo API.',
            $respuesta->status() === 403 => 'El token de SISPAM no tiene permiso para hacer esto en Authentik. Revisa los permisos de su cuenta de servicio (docs/despliegue.md).',
            $respuesta->status() === 400 => 'Authentik no aceptó los datos: '.self::errores($respuesta),
            $respuesta->serverError() => 'Authentik tuvo un error (HTTP '.$respuesta->status().'). Intenta de nuevo en un momento.',
            default => 'Authentik respondió HTTP '.$respuesta->status().'.',
        });
    }

    /**
     * Los errores de validación de Authentik en una línea:
     * {"username": ["…"]} → «username: …».
     */
    private static function errores(Response $respuesta): string
    {
        $json = $respuesta->json();

        if (! is_array($json)) {
            return mb_substr(trim(strip_tags($respuesta->body())), 0, 200);
        }

        return collect($json)
            ->map(fn (mixed $mensajes, int|string $campo): string => (is_string($campo) ? "{$campo}: " : '')
                .implode(' ', array_map('strval', Arr::flatten((array) $mensajes))))
            ->implode(' · ');
    }
}
