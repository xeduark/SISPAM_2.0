<?php

namespace App\Services\Tickets;

use App\Models\Cola;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Turnos\GeneradorDeTurnos;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Crea el ticket de una visita a partir del paciente y su orden médica.
 *
 * Nace en `generado` y **sin medicamentos**: los captura farmacia al alistar.
 */
class GenerarTicket
{
    public function __construct(
        private GeneradorDeTurnos $turnos,
    ) {}

    /**
     * @param  array{prioridad?: string, motivo_prioridad?: ?string, observaciones?: ?string}  $datos
     */
    public function handle(Paciente $paciente, Sede $sede, User $usuario, array $datos = [], ?CarbonInterface $fecha = null): Ticket
    {
        $dia = $fecha ?? now();

        $cola = $this->colaPara($sede);

        return DB::transaction(function () use ($paciente, $sede, $cola, $usuario, $datos, $dia): Ticket {
            // Numera la sede, no la cola: el turno ya no lleva prefijo, así que
            // dos colas contando aparte sacarían el mismo «0060».
            $turno = $this->turnos->siguiente($sede, $dia);

            return Ticket::create([
                'numero' => $this->numero($sede, $turno, $dia),
                'turno' => $turno,
                'fecha' => $dia->toDateString(),
                'paciente_id' => $paciente->getKey(),
                'sede_id' => $sede->getKey(),
                'cola_id' => $cola->getKey(),
                'estado' => Ticket::ESTADO_GENERADO,
                'prioridad' => $datos['prioridad'] ?? Ticket::PRIORIDAD_NORMAL,
                'motivo_prioridad' => $datos['motivo_prioridad'] ?? null,
                // Nace en falso: lo marca farmacia al alistar, que es quien ve
                // los medicamentos.
                'alto_costo' => false,
                'creado_por' => $usuario->getKey(),
                'observaciones' => $datos['observaciones'] ?? null,
            ]);
        });
    }

    /**
     * La cola que le toca.
     *
     * **El alto costo ya no decide fila.** Esa marca la pone farmacia al
     * alistar, cuando ve los medicamentos; aquí todavía no se sabe. Así que
     * todos los tickets nacen en la cola general de la sede.
     *
     * Si una sede solo dejó activa la de alto costo, se usa esa: el paciente
     * se atiende igual, que es lo que importa.
     */
    public function colaPara(Sede $sede): Cola
    {
        $activas = $sede->colas()->where('activa', true)->orderBy('orden');

        $cola = (clone $activas)->where('atiende_alto_costo', false)->first()
            ?? (clone $activas)->first();

        if ($cola === null) {
            throw new RuntimeException("La sede «{$sede->nombre}» no tiene colas activas. Crea al menos una en Administración → Colas.");
        }

        return $cola;
    }

    /**
     * Número único en todo el sistema: TK-PRP-261005-0060.
     *
     * Lleva la sede y la fecha, así que no se repite entre sedes ni entre
     * días, y el turno dentro ya es único en su cola. Por eso el módulo de
     * entrega puede seguir buscando solo por número.
     *
     * Los tickets emitidos antes conservan el formato viejo
     * (`SP-LA30-20261003-A023`): entrega los busca por número exacto, así que
     * reescribirlos sería dejarlos sin encontrar. Conviven sin problema.
     */
    private function numero(Sede $sede, string $turno, CarbonInterface $fecha): string
    {
        $codigo = $sede->codigo ?: 'S'.$sede->getKey();

        return 'TK-'.strtoupper($codigo).'-'.$fecha->format('ymd').'-'.str_replace('-', '', $turno);
    }
}
