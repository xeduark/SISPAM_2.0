<?php

namespace App\Filament\Resources\PacienteResource\Concerns;

use Filament\Actions\Action;
use Filament\Actions\StaticAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\MaxWidth;

/**
 * Avisos de Savia mostrados con un modal centrado.
 *
 * Los mensajes son para una persona: no llevan el código interno del servicio
 * ni el HTTP, que quedan registrados en el log de auditoría de `SaviaClient`.
 */
trait MuestraAvisosDeSavia
{
    /* ------------------------------------------------------------------ *
     *  El afiliado no existe en Savia
     * ------------------------------------------------------------------ */

    public function mostrarAfiliadoNoEncontrado(
        string $tipoDocumento,
        string $numeroDocumento,
        bool $existeEnSispam = false,
    ): void {
        $this->mountAction('afiliadoNoEncontrado', [
            'tipo' => $tipoDocumento,
            'numero' => $numeroDocumento,
            'enSispam' => $existeEnSispam,
        ]);
    }

    public function afiliadoNoEncontradoAction(): Action
    {
        return $this->avisoDeSavia('afiliadoNoEncontrado')
            ->modalHeading('El afiliado no aparece en Savia')
            ->modalDescription(function (array $arguments): string {
                $documento = trim(($arguments['tipo'] ?? '').' '.($arguments['numero'] ?? ''));

                // Un paciente ya registrado que Savia deja de reconocer no es un
                // error de digitación: es una novedad que hay que aclarar con la EPS.
                if ($arguments['enSispam'] ?? false) {
                    return "El documento {$documento} está registrado en SISPAM, pero Savia Salud EPS "
                        .'no lo reconoce hoy. No se modificó nada del paciente. '
                        .'Revisa el documento o confirma la novedad con la EPS.';
                }

                return "Savia Salud EPS no tiene ningún afiliado con el documento {$documento}. "
                    .'Revisa que el tipo y el número estén bien escritos.';
            });
    }

    /* ------------------------------------------------------------------ *
     *  El afiliado existe pero no está activo
     * ------------------------------------------------------------------ */

    public function mostrarAfiliadoInactivo(?string $estadoAfiliacion, ?string $causaEstado = null): void
    {
        $this->mountAction('afiliadoInactivo', [
            'estado' => $estadoAfiliacion,
            'causa' => $causaEstado,
        ]);
    }

    public function afiliadoInactivoAction(): Action
    {
        return $this->avisoDeSavia('afiliadoInactivo')
            ->modalHeading('El afiliado no está activo en Savia')
            ->modalDescription(function (array $arguments): string {
                $estado = trim((string) ($arguments['estado'] ?? '')) ?: 'sin estado';
                $causa = trim((string) ($arguments['causa'] ?? ''));

                return "Savia reporta el estado «{$estado}»"
                    .($causa !== '' ? " por {$causa}" : '')
                    .'. Verifícalo antes de dispensar.';
            });
    }

    /* ------------------------------------------------------------------ *
     *  Estilo común de los dos avisos
     * ------------------------------------------------------------------ */

    private function avisoDeSavia(string $nombre): Action
    {
        return Action::make($nombre)
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('danger')
            ->modalAlignment(Alignment::Center)
            ->modalFooterActionsAlignment(Alignment::Center)
            ->modalWidth(MaxWidth::Large)
            ->modalSubmitAction(false)
            ->modalCancelAction(fn (StaticAction $action): StaticAction => $action
                ->label('Aceptar')
                ->color('success')
                ->button());
    }
}
