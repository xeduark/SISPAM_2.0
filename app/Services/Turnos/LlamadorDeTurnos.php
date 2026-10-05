<?php

namespace App\Services\Turnos;

use App\Models\Llamado;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Ventanilla;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Llama turnos a las ventanillas.
 *
 * Tres reglas:
 *
 * 1. **Los preferenciales de primeras**, y dentro de cada prioridad el que
 *    llegó primero. La prioridad se resuelve al llamar y no al entregar el
 *    turno: así el orden se puede corregir toda la mañana.
 * 2. **Solo se llama lo que farmacia dejó listo** (`listo` o `parcial`). Un
 *    ticket sin alistar no sirve de nada en la ventanilla.
 * 3. **Dos ventanillas no se llevan el mismo turno.** El candidato se toma con
 *    `lockForUpdate` dentro de una transacción, igual que el consecutivo en
 *    `GeneradorDeTurnos`.
 *
 * Llamar **no toca `tickets.estado`**: mueve `estado_sala`. El por qué está en
 * el comentario de `Ticket` y en la migración `crear_llamados`.
 */
class LlamadorDeTurnos
{
    /**
     * Llama al siguiente paciente de la sala a esta ventanilla.
     *
     * Devuelve `null` si no hay nadie esperando.
     *
     * @param  list<int>  $colaIds  Colas de las que se quiere llamar; vacío son todas.
     */
    public function siguiente(Ventanilla $ventanilla, User $usuario, array $colaIds = [], ?CarbonInterface $fecha = null): ?Ticket
    {
        return DB::transaction(function () use ($ventanilla, $usuario, $colaIds, $fecha): ?Ticket {
            $ticket = $this->consultaDeEspera((int) $ventanilla->sede_id, $colaIds, $fecha)
                // Si otra ventanilla pidió turno en este mismo instante, espera
                // aquí y vuelve a leer: ya lo verá llamado y seguirá al otro.
                ->lockForUpdate()
                ->first();

            if ($ticket === null) {
                return null;
            }

            $this->llamar($ticket, $ventanilla, $usuario);

            return $ticket;
        });
    }

    /**
     * Llama un turno puntual: el que se escogió de la lista, o el que no se
     * presentó y se vuelve a llamar.
     */
    public function llamar(Ticket $ticket, Ventanilla $ventanilla, User $usuario): Llamado
    {
        if ((int) $ticket->sede_id !== (int) $ventanilla->sede_id) {
            throw new InvalidArgumentException(
                "El turno {$ticket->turno} es de otra sede: no se puede llamar desde la ventanilla «{$ventanilla->nombre}».",
            );
        }

        if (! $ticket->sePuedeLlamar()) {
            throw new InvalidArgumentException(
                "El turno {$ticket->turno} está «{$ticket->estado_etiqueta}» y ya no se llama.",
            );
        }

        return DB::transaction(function () use ($ticket, $ventanilla, $usuario): Llamado {
            $intento = $ticket->llamados()->count() + 1;

            $llamado = Llamado::create([
                'ticket_id' => $ticket->getKey(),
                'sede_id' => $ticket->sede_id,
                'cola_id' => $ticket->cola_id,
                'ventanilla_id' => $ventanilla->getKey(),
                'llamado_por' => $usuario->getKey(),
                'intento' => $intento,
            ]);

            // El ticket guarda el último llamado; el historial queda en `llamados`.
            $ticket->update([
                'estado_sala' => Ticket::SALA_LLAMADO,
                'ventanilla_id' => $ventanilla->getKey(),
                'llamado_por' => $usuario->getKey(),
                'llamado_en' => $llamado->created_at,
            ]);

            return $llamado;
        });
    }

    /**
     * El paciente no apareció. Sale de la espera, pero el turno no se pierde:
     * se puede volver a llamar desde la lista de los que no se presentaron.
     */
    public function marcarAusente(Ticket $ticket): Ticket
    {
        if (! $ticket->fueLlamado()) {
            throw new InvalidArgumentException(
                "El turno {$ticket->turno} no está llamado, así que no se puede marcar como ausente.",
            );
        }

        $ticket->update(['estado_sala' => Ticket::SALA_AUSENTE]);

        return $ticket;
    }

    /**
     * El turno ya pasó por la ventanilla. Esto lo dispara el cierre del ticket,
     * no la pantalla: quien sabe que la atención se registró es entrega.
     */
    public function marcarAtendido(Ticket $ticket): Ticket
    {
        $ticket->update(['estado_sala' => Ticket::SALA_ATENDIDO]);

        return $ticket;
    }

    /**
     * Los que están esperando, en el orden en que se van a llamar.
     *
     * @param  list<int>  $colaIds
     * @return Collection<int, Ticket>
     */
    public function enEspera(int $sedeId, array $colaIds = [], ?CarbonInterface $fecha = null, int $limite = 25): Collection
    {
        return $this->consultaDeEspera($sedeId, $colaIds, $fecha)
            ->with(['cola', 'paciente'])
            ->limit($limite)
            ->get();
    }

    /**
     * Los que se llamaron y no aparecieron. Van aparte porque «llamar al
     * siguiente» no los vuelve a tomar: alguien decide si los llama otra vez.
     *
     * @param  list<int>  $colaIds
     * @return Collection<int, Ticket>
     */
    public function ausentes(int $sedeId, array $colaIds = [], ?CarbonInterface $fecha = null): Collection
    {
        return $this->consultaDelDia($sedeId, $colaIds, $fecha)
            ->where('estado_sala', Ticket::SALA_AUSENTE)
            ->with(['cola', 'ventanilla'])
            ->orderBy('llamado_en')
            ->get();
    }

    /**
     * El turno que esta ventanilla tiene en la mano, mientras no lo cierre.
     */
    public function enAtencion(Ventanilla $ventanilla, ?CarbonInterface $fecha = null): ?Ticket
    {
        return $this->consultaDelDia((int) $ventanilla->sede_id, [], $fecha)
            ->where('ventanilla_id', $ventanilla->getKey())
            ->where('estado_sala', Ticket::SALA_LLAMADO)
            ->with(['cola', 'paciente'])
            ->latest('llamado_en')
            ->first();
    }

    /**
     * Los últimos llamados de la sede: es lo que sale en la pantalla de la sala.
     *
     * @return Collection<int, Llamado>
     */
    public function ultimosLlamados(int $sedeId, int $limite = 5, ?CarbonInterface $fecha = null): Collection
    {
        return Llamado::query()
            ->where('sede_id', $sedeId)
            ->whereDate('created_at', ($fecha ?? now())->toDateString())
            ->with(['ticket:id,turno,prioridad,estado_sala', 'ventanilla:id,nombre'])
            ->latest('id')
            ->limit($limite)
            ->get();
    }

    /**
     * Cuántos esperan en la sede. Sale como aviso en el menú.
     *
     * @param  list<int>  $colaIds
     */
    public function cuantosEsperan(int $sedeId, array $colaIds = [], ?CarbonInterface $fecha = null): int
    {
        return $this->consultaDeEspera($sedeId, $colaIds, $fecha)->count();
    }

    /**
     * La espera: alistados, sin llamar, en el orden de llamado.
     *
     * @param  list<int>  $colaIds
     * @return Builder<Ticket>
     */
    private function consultaDeEspera(int $sedeId, array $colaIds = [], ?CarbonInterface $fecha = null): Builder
    {
        return $this->consultaDelDia($sedeId, $colaIds, $fecha)
            ->where('estado_sala', Ticket::SALA_EN_ESPERA)
            // Los preferenciales de primeras; dentro de cada grupo, por llegada.
            ->orderByRaw('CASE WHEN prioridad = ? THEN 0 ELSE 1 END', [Ticket::PRIORIDAD_PREFERENCIAL])
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Los tickets atendibles de hoy en la sede. Base de todo lo demás.
     *
     * @param  list<int>  $colaIds
     * @return Builder<Ticket>
     */
    private function consultaDelDia(int $sedeId, array $colaIds = [], ?CarbonInterface $fecha = null): Builder
    {
        return Ticket::query()
            ->where('sede_id', $sedeId)
            ->whereDate('fecha', ($fecha ?? now())->toDateString())
            ->whereIn('estado', Ticket::ESTADOS_ENTREGABLES)
            ->when($colaIds !== [], fn (Builder $q) => $q->whereIn('cola_id', $colaIds));
    }
}
