<?php

namespace App\Filament\Resources\PacienteResource\Pages;

use App\Filament\Resources\PacienteResource;
use App\Models\Paciente;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPaciente extends ViewRecord
{
    protected static string $resource = PacienteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('actualizarDesdeSavia')
                ->label('Actualizar desde Savia')
                ->icon('heroicon-m-arrow-path')
                ->requiresConfirmation()
                ->modalHeading('Actualizar desde Savia')
                ->modalDescription('Se vuelve a consultar el servicio y los datos del paciente se reemplazan con lo que responda Savia.')
                ->modalSubmitActionLabel('Consultar')
                ->action(function (): void {
                    /** @var Paciente $paciente */
                    $paciente = $this->getRecord();

                    $resultado = PacienteResource::consultarEnSavia(
                        $paciente->tipo_documento,
                        $paciente->numero_documento,
                    );

                    if ($resultado !== null) {
                        // El contacto confirmado no se toca.
                        $paciente->update($resultado->atributos);
                    }
                }),
            Actions\EditAction::make(),
        ];
    }
}
