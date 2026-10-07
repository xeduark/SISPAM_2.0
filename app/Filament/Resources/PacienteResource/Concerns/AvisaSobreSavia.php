<?php

namespace App\Filament\Resources\PacienteResource\Concerns;

/**
 * Páginas que avisan de los resultados de Savia con un modal centrado en vez
 * de una notificación en la esquina.
 *
 * `PacienteResource::consultarEnSavia()` usa estos avisos cuando la página que
 * consulta los ofrece; si no, envía las notificaciones de siempre.
 */
interface AvisaSobreSavia
{
    public function mostrarAfiliadoNoEncontrado(
        string $tipoDocumento,
        string $numeroDocumento,
        bool $existeEnSispam = false,
    ): void;

    public function mostrarAfiliadoInactivo(?string $estadoAfiliacion, ?string $causaEstado = null): void;
}
