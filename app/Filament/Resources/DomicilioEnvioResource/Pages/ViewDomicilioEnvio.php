<?php

namespace App\Filament\Resources\DomicilioEnvioResource\Pages;

use App\Filament\Resources\DomicilioEnvioResource;
use App\Models\DomicilioEnvio;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewDomicilioEnvio extends ViewRecord
{
    protected static string $resource = DomicilioEnvioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('cambiarEstado')
                ->label('Cambiar estado')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => (bool) auth()->user()?->puede('entrega.domicilio'))
                ->fillForm(fn (): array => [
                    'estado' => $this->record->estado,
                    'novedad_detalle' => $this->record->novedad_detalle,
                ])
                ->form(DomicilioEnvioResource::formularioCambioEstado())
                ->action(function (array $data): void {
                    /** @var DomicilioEnvio $record */
                    $record = $this->record;
                    DomicilioEnvioResource::aplicarCambioEstado($record, $data);
                    $this->refreshFormData(['estado', 'novedad_detalle', 'referencia_externa']);
                    $this->record->refresh()->load('historial');
                }),
        ];
    }
}
