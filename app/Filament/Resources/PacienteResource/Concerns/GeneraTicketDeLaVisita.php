<?php

namespace App\Filament\Resources\PacienteResource\Concerns;

use App\Models\Paciente;
use App\Services\Tickets\GenerarTicket;
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
     * @param  array{alto_costo: bool, prioridad: string, motivo_prioridad: ?string}  $datosTicket
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

        Notification::make()
            ->title("Ticket {$ticket->turno} generado")
            ->body("Número {$ticket->numero}. Farmacia lo alista y queda listo para entrega.")
            ->success()
            ->persistent()
            ->send();
    }
}
