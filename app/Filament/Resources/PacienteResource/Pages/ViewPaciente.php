<?php

namespace App\Filament\Resources\PacienteResource\Pages;

use App\Filament\Resources\PacienteResource;
use App\Models\Auditoria;
use App\Models\Paciente;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPaciente extends ViewRecord
{
    protected static string $resource = PacienteResource::class;

    /**
     * Abrir la ficha de un paciente con fórmulas es entrar a su galería.
     *
     * Queda **una sola línea** por visita a la ficha, no una por miniatura:
     * ver las hojas de lejos no es leer la fórmula. Leer una concreta se sigue
     * registrando por separado, en `OrdenMedicaController`.
     *
     * Solo se registra si de verdad se ven: sin el permiso la sección no se
     * pinta, y sin fórmulas no hay nada que ver.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Paciente $paciente */
        $paciente = $this->getRecord();

        if (! auth()->user()?->puede('orientacion.ver_orden') || ! $paciente->soportes()->exists()) {
            return;
        }

        Auditoria::registrar(
            accion: Auditoria::ACCION_VIO_GALERIA,
            descripcion: 'Vio las fórmulas del paciente '.$paciente->documento_completo,
            entidadTipo: 'paciente',
            entidadId: $paciente->getKey(),
        );
    }

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
