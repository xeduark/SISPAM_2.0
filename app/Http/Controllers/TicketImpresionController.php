<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Contracts\View\View;

/**
 * El ticket impreso que se lleva el paciente.
 *
 * ## Qué sale y qué no
 *
 * Sale lo que el paciente necesita para reconocer su papel y esperar su turno:
 * la sede con su código, el turno en grande, el número, **su nombre**, la
 * fecha, la hora y la prioridad.
 *
 * **Lo clínico no entra:** ni su documento, ni los medicamentos, ni el nombre
 * de la cola —que delataría que va a alto costo y oncológicos—, ni el motivo
 * de la prioridad («gestante», «discapacidad»). Un ticket impreso se queda en
 * un mostrador, se cae al piso y lo recoge cualquiera: que diga a nombre de
 * quién es no cuenta nada de su salud; que diga a qué cola va, sí.
 *
 * Por eso la prioridad sale como «NORMAL» o «PREFERENCIAL» y el motivo nunca.
 *
 * ## Permisos
 *
 * Exige `tickets.imprimir`, que es **aparte de `tickets.ver`**: el orientador
 * genera el ticket y tiene que poder imprimírselo al paciente, pero no entra
 * al listado de Tickets.
 *
 * Y cada quien imprime solo lo de su sede, salvo el administrador: la misma
 * regla de `FiltraPorSede`.
 */
class TicketImpresionController extends Controller
{
    public function mostrar(Ticket $ticket): View
    {
        $usuario = auth()->user();

        abort_unless(
            (bool) $usuario?->puede('tickets.imprimir'),
            403,
            'No tienes permiso para imprimir tickets.',
        );

        abort_unless(
            $usuario->es_administrador || (int) $ticket->sede_id === (int) $usuario->sede_id,
            403,
            'Ese ticket es de otra sede.',
        );

        $ticket->loadMissing(['sede', 'paciente']);

        return view('tickets.impresion', [
            'ticket' => $ticket,
            // La etiqueta se arma en un solo sitio, igual que en los
            // selectores: «PREMIUM PLAZA (PRP)».
            'sede' => $ticket->sede?->etiqueta ?? 'SEDE SIN REGISTRAR',
            'sedeNombre' => $ticket->sede?->nombre ?? 'SEDE SIN REGISTRAR',
            'paciente' => $ticket->paciente?->nombre_completo ?: 'SIN REGISTRAR',
            // El qué, nunca el porqué: `motivo_prioridad` no sale del sistema.
            'prioridad' => mb_strtoupper(Ticket::PRIORIDADES[$ticket->prioridad] ?? $ticket->prioridad),
            'tamanoTurno' => $this->tamanoDelTurno((string) $ticket->turno),
        ]);
    }

    /**
     * Qué tan grande va el turno, en puntos, para que ocupe **todo el ancho**
     * del papel.
     *
     * Es lo único que el paciente mira de lejos, así que tiene que ser lo más
     * grande que quepa. Pero no todos los turnos miden igual: «A-0001» son
     * seis caracteres y «AC-10000» son ocho. Con un tamaño fijo, o el corto se
     * ve pequeño o el largo se sale del papel.
     *
     * La cuenta: el contenido mide 66 mm útiles (≈ 187 pt) y una tipografía
     * monoespaciada avanza 0,6 em por carácter. De ahí sale el tamaño que hace
     * que el turno llegue justo de borde a borde.
     *
     * Por eso la vista pone `letter-spacing: 0` en el turno: cualquier
     * separación extra rompería la cuenta.
     */
    private function tamanoDelTurno(string $turno): int
    {
        $caracteres = max(1, mb_strlen(trim($turno)));

        // El tope de 80 pt evita que un turno muy corto quede desproporcionado;
        // el piso de 28 pt, que uno larguísimo quede ilegible.
        return (int) max(28, min(80, floor(187 / ($caracteres * 0.6))));
    }
}
