<?php

namespace App\Livewire;

use App\Models\Sede;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * El selector de sede de la barra superior.
 *
 * Deja moverse entre las sedes donde la persona tiene permiso de trabajar.
 * **Solo aparece si tiene más de una**: quien trabaja siempre en el mismo
 * sitio no necesita verlo, y así la barra no se llena de controles que nadie
 * usa.
 *
 * Cambiar de sede escribe `users.sede_id`, que es lo que lee todo el sistema,
 * así que el cambio vale de inmediato para tickets, turnos, entrega,
 * transcripción e inventario. Por eso después se recarga la página: media
 * pantalla mostraría todavía lo de la sede anterior.
 */
class SelectorDeSede extends Component
{
    /**
     * Dónde guarda «Llamar turnos» la ventanilla escogida.
     *
     * Al mudarse de sede hay que soltarla: es una ventanilla de la sede
     * anterior, y quien llame turnos desde la nueva estaría llamando con el
     * mostrador equivocado.
     */
    private const SESION_VENTANILLA = 'turnos.ventanilla';

    public function cambiar(int $sedeId): void
    {
        $usuario = auth()->user();
        $sede = Sede::find($sedeId);

        if ($usuario === null || $sede === null) {
            return;
        }

        // El selector solo ofrece las permitidas, pero el id viaja en la
        // petición: la comprobación de verdad está en el modelo.
        if (! $usuario->cambiarDeSede($sede)) {
            Notification::make()
                ->title('No puedes trabajar en esa sede')
                ->body('Pídele a un administrador que te habilite en ella.')
                ->danger()
                ->send();

            return;
        }

        session()->forget(self::SESION_VENTANILLA);

        Notification::make()
            ->title("Estás en {$sede->etiqueta}")
            ->body('Lo que ves y lo que atiendes es de esta sede.')
            ->success()
            ->send();

        // Recarga completa: los listados, los avisos del menú y la pantalla
        // abierta se arman con la sede y se quedarían en la anterior.
        $this->redirect(request()->header('Referer') ?: '/admin', navigate: false);
    }

    public function render(): View
    {
        $usuario = auth()->user();

        return view('livewire.selector-de-sede', [
            'actual' => $usuario?->sede,
            'disponibles' => $usuario?->sedesDondePuedeTrabajar() ?? collect(),
            'visible' => (bool) $usuario?->puedeCambiarDeSede(),
        ]);
    }
}
