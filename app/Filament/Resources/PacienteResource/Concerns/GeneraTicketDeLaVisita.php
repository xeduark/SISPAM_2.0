<?php

namespace App\Filament\Resources\PacienteResource\Concerns;

use App\Models\Paciente;
use App\Services\Tickets\GenerarTicket;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use RuntimeException;

/**
 * Genera el ticket de la visita y le cuelga la orden médica.
 *
 * Lo usan `CreatePaciente` y `EditPaciente`: en los dos, cargar una orden
 * médica abre una visita nueva.
 */
trait GeneraTicketDeLaVisita
{
    /**
     * @param  array<string, mixed>  $soporte  La orden médica ya separada del formulario
     * @param  array{prioridad: string, motivo_prioridad: ?string}  $datosTicket
     */
    protected function generarTicketDeLaVisita(Paciente $paciente, array $soporte, array $datosTicket): void
    {
        $usuario = auth()->user();

        try {
            $ticket = app(GenerarTicket::class)->handle($paciente, $usuario->sede, $usuario, $datosTicket);
        } catch (RuntimeException $e) {
            // La sede no está configurada. El paciente y su orden médica igual
            // quedan guardados: perder la orientación —la consulta a Savia, el
            // contacto confirmado y la orden cargada— por un problema de
            // configuración sería mucho peor que quedarse sin ticket.
            $paciente->soportes()->create($soporte);

            Notification::make()
                ->title('El paciente quedó registrado, pero sin ticket')
                ->body($e->getMessage().' La orden médica se guardó: cuando se arregle la configuración, se puede generar el ticket volviendo a editar el paciente.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $paciente->soportes()->create($soporte + ['ticket_id' => $ticket->getKey()]);

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
    }
}
