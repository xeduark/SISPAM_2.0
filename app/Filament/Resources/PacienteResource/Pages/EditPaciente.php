<?php

namespace App\Filament\Resources\PacienteResource\Pages;

use App\Filament\Resources\PacienteResource;
use App\Filament\Resources\PacienteResource\Concerns\GeneraTicketDeLaVisita;
use App\Models\Paciente;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPaciente extends EditRecord
{
    use GeneraTicketDeLaVisita;

    protected static string $resource = PacienteResource::class;

    /**
     * Editar también exige confirmar el contacto con el paciente, así que el
     * sello se actualiza con cada guardado.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['contacto_confirmado_at'] = now();
        $data['contacto_confirmado_por'] = auth()->id();

        return $data;
    }

    /**
     * Si el orientador cargó una orden nueva, queda como otro soporte del paciente.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $soporte = PacienteResource::separarSoporte($data);
        $datosTicket = PacienteResource::separarDatosDelTicket($data);

        $record->update($data);

        // Una orden médica nueva es una visita nueva: lleva su propio ticket.
        if ($soporte !== null) {
            $this->generarTicketDeLaVisita($record, $soporte, $datosTicket);
        }

        return $record;
    }

    /**
     * «Guardar» va en el último paso del asistente; abajo solo queda «Cancelar».
     */
    protected function getFormActions(): array
    {
        return [$this->getCancelFormAction()];
    }

    /**
     * @return array<Actions\Action>
     */
    public function accionesDeGuardado(): array
    {
        return [$this->getSaveFormAction()->livewire($this)];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('actualizarDesdeSavia')
                ->label('Actualizar desde Savia')
                ->icon('heroicon-m-arrow-path')
                ->requiresConfirmation()
                ->modalHeading('Actualizar desde Savia')
                ->modalDescription('Se vuelve a consultar el servicio y los datos del formulario se reemplazan con lo que responda Savia.')
                ->modalSubmitActionLabel('Consultar')
                ->action(function (): void {
                    /** @var Paciente $paciente */
                    $paciente = $this->getRecord();

                    $resultado = PacienteResource::consultarEnSavia(
                        $paciente->tipo_documento,
                        $paciente->numero_documento,
                    );

                    if ($resultado === null) {
                        return;
                    }

                    // El contacto confirmado no se toca.
                    $paciente->update($resultado->atributos);

                    // Refresca el formulario con lo que quedó guardado.
                    $this->fillForm();
                }),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
