<?php

namespace App\Filament\Resources\PacienteResource\Concerns;

use App\Models\Paciente;
use App\Models\Soporte;
use App\Models\Ticket;
use App\Services\Tickets\GenerarTicket;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Genera el ticket de la visita y le cuelga la orden médica.
 *
 * Lo usan `CreatePaciente` y `EditPaciente`: en los dos, cargar una orden
 * médica abre una visita nueva.
 *
 * La clave de idempotencia es la ruta del archivo (`soportes.orden_medica`):
 * Filament guarda cada upload nuevo con un ULID distinto, así que la misma
 * ruta significa la misma orden ya procesada (p. ej. un segundo Guardar con
 * el FileUpload todavía lleno). No se usa «paciente con ticket activo».
 */
trait GeneraTicketDeLaVisita
{
    /**
     * @param  array<string, mixed>  $soporte  La orden médica ya separada del formulario
     * @param  array{prioridad: string, motivo_prioridad: ?string}  $datosTicket
     */
    protected function generarTicketDeLaVisita(Paciente $paciente, array $soporte, array $datosTicket): void
    {
        $ruta = $soporte['orden_medica'] ?? null;

        if (blank($ruta) || ! is_string($ruta)) {
            return;
        }

        $usuario = auth()->user();

        try {
            $resultado = DB::transaction(function () use ($paciente, $soporte, $datosTicket, $ruta, $usuario): array {
                // Bloquea al paciente: dos Guardar casi a la vez no crean dos visitas.
                Paciente::query()->whereKey($paciente->getKey())->lockForUpdate()->first();

                $existente = Soporte::query()
                    ->where('orden_medica', $ruta)
                    ->whereNotNull('ticket_id')
                    ->with('ticket')
                    ->lockForUpdate()
                    ->first();

                if ($existente?->ticket) {
                    return [
                        'nuevo' => false,
                        'ticket' => $existente->ticket,
                    ];
                }

                // Orden guardada antes sin ticket (sede sin colas): se intenta completar.
                $huerfano = Soporte::query()
                    ->where('orden_medica', $ruta)
                    ->whereNull('ticket_id')
                    ->lockForUpdate()
                    ->first();

                $ticket = app(GenerarTicket::class)->handle(
                    $paciente,
                    $usuario->sede,
                    $usuario,
                    $datosTicket,
                );

                if ($huerfano) {
                    $huerfano->update(['ticket_id' => $ticket->getKey()]);
                } else {
                    $paciente->soportes()->create($soporte + ['ticket_id' => $ticket->getKey()]);
                }

                return [
                    'nuevo' => true,
                    'ticket' => $ticket,
                ];
            });
        } catch (RuntimeException $e) {
            // La sede no está configurada. El paciente y su orden médica igual
            // quedan guardados: perder la orientación por un problema de
            // configuración sería mucho peor que quedarse sin ticket.
            $yaHaySoporte = Soporte::query()->where('orden_medica', $ruta)->exists();

            if (! $yaHaySoporte) {
                $paciente->soportes()->create($soporte);
            }

            $this->limpiarOrdenMedicaDelFormulario();

            Notification::make()
                ->title('El paciente quedó registrado, pero sin ticket')
                ->body($e->getMessage().' La orden médica se guardó: cuando se arregle la configuración, se puede generar el ticket volviendo a editar el paciente.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->limpiarOrdenMedicaDelFormulario();

        /** @var Ticket $ticket */
        $ticket = $resultado['ticket'];

        if ($resultado['nuevo']) {
            $aviso = Notification::make()
                ->title("Ticket {$ticket->turno} generado")
                ->body("Número {$ticket->numero}. Farmacia lo alista y queda listo para entrega.")
                ->success()
                ->persistent();

            // El papel que se lleva el paciente. Se abre en otra pestaña para no
            // perder la ficha que se acaba de guardar.
            if ($usuario->puede('tickets.imprimir')) {
                $aviso->actions([
                    Action::make('imprimir')
                        ->label('Imprimir ticket')
                        ->icon('heroicon-m-printer')
                        ->url(route('tickets.imprimir', $ticket), shouldOpenInNewTab: true)
                        ->close(),
                ]);
            }

            $aviso->send();

            return;
        }

        Notification::make()
            ->title('Orden médica ya procesada')
            ->body("Esta orden médica ya tiene un ticket generado: {$ticket->turno}.")
            ->warning()
            ->persistent()
            ->send();
    }

    /**
     * Evita que un segundo Guardar en la misma pantalla vuelva a tratar la
     * orden ya guardada como una visita nueva. No borra el archivo ni el Soporte.
     */
    protected function limpiarOrdenMedicaDelFormulario(): void
    {
        if (! property_exists($this, 'data') || ! is_array($this->data)) {
            return;
        }

        $this->data['orden_medica'] = null;
    }
}
