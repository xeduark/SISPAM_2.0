<?php

namespace App\Filament\Resources\PacienteResource\Pages;

use App\Filament\Resources\PacienteResource;
use App\Filament\Resources\PacienteResource\Concerns\AvisaSobreSavia;
use App\Filament\Resources\PacienteResource\Concerns\GeneraTicketDeLaVisita;
use App\Filament\Resources\PacienteResource\Concerns\MuestraAvisosDeSavia;
use App\Models\Paciente;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePaciente extends CreateRecord implements AvisaSobreSavia
{
    use GeneraTicketDeLaVisita, MuestraAvisosDeSavia;

    protected static string $resource = PacienteResource::class;

    public function getTitle(): string
    {
        return $this->pacienteExistente() !== null
            ? 'Actualizar paciente'
            : 'Registrar paciente';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * Los botones de guardar van en el último paso del asistente; abajo
     * solo queda «Cancelar», que sirve incluso antes de consultar.
     */
    protected function getFormActions(): array
    {
        return [$this->getCancelFormAction()];
    }

    /**
     * Botones del último paso del asistente. Mientras la consulta a Savia no
     * traiga al afiliado no hay nada que guardar, así que se ocultan con un
     * closure: `hidden()` se evalúa al renderizar, después de la consulta.
     *
     * @return array<Action>
     */
    public function accionesDeGuardado(): array
    {
        $sinConsulta = fn (): bool => ! $this->hayConsultaVigente();

        return array_map(fn (Action $accion): Action => $accion->livewire($this), [
            $this->getCreateFormAction()
                // El mismo flujo sirve para registrar y para actualizar: el botón
                // dice lo que de verdad va a pasar.
                ->label(fn (): string => $this->pacienteExistente() !== null
                    ? 'Actualizar paciente'
                    : 'Crear paciente')
                ->hidden($sinConsulta),
            ...(static::canCreateAnother()
                ? [$this->getCreateAnotherFormAction()
                    // Crear otro solo tiene sentido cuando de verdad se está creando.
                    ->hidden(fn (): bool => $sinConsulta() || $this->pacienteExistente() !== null)]
                : []),
        ]);
    }

    /**
     * La misma comprobación en el servidor, porque ocultar los botones no
     * impide una petición armada a mano: solo se guarda si hubo una consulta
     * exitosa para ese mismo tipo y número de documento.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $esperado = PacienteResource::claveDocumento(
            $data['tipo_documento'] ?? null,
            $data['numero_documento'] ?? null,
        );

        if (($data['documento_consultado'] ?? null) !== $esperado) {
            Notification::make()
                ->title('Primero consulta el documento en Savia')
                ->body('Solo se registran pacientes con una consulta exitosa para ese mismo tipo y número de documento.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }

        // La marca no es columna de la tabla.
        unset($data['documento_consultado']);

        // Queda constancia de quién confirmó el contacto con el paciente y cuándo.
        $data['contacto_confirmado_at'] = now();
        $data['contacto_confirmado_por'] = auth()->id();

        return $data;
    }

    /**
     * Si el documento ya está en SISPAM no se crea un duplicado: se actualiza
     * ese paciente. El redirect lleva igual a su ficha.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $paciente = Paciente::firstOrNew([
            'tipo_documento' => $data['tipo_documento'],
            'numero_documento' => $data['numero_documento'],
        ]);

        $soporte = PacienteResource::separarSoporte($data);
        $datosTicket = PacienteResource::separarDatosDelTicket($data, $soporte);

        $paciente->fill($data)->save();

        // La orden médica abre una visita: eso es lo que genera el ticket.
        if ($soporte !== null) {
            $this->generarTicketDeLaVisita($paciente, $soporte, $datosTicket);
        }

        return $paciente;
    }

    /**
     * Los datos cargados corresponden al documento que está escrito.
     */
    private function hayConsultaVigente(): bool
    {
        $marca = $this->data['documento_consultado'] ?? null;

        return filled($marca) && $marca === PacienteResource::claveDocumento(
            $this->data['tipo_documento'] ?? null,
            $this->data['numero_documento'] ?? null,
        );
    }

    /**
     * El paciente que la consulta encontró ya registrado, si lo hay.
     */
    private function pacienteExistente(): ?int
    {
        $id = $this->data['paciente_existente_id'] ?? null;

        return filled($id) ? (int) $id : null;
    }
}
