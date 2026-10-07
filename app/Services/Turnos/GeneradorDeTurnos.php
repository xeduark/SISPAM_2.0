<?php

namespace App\Services\Turnos;

use App\Models\Cola;
use App\Models\ContadorTurno;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Entrega el siguiente turno de una cola: «A-023».
 *
 * El consecutivo es **por cola y por día**, así que cada mañana vuelve a
 * empezar en 1 y dos colas de la misma sede no se pisan.
 *
 * Varios orientadores piden turno al mismo tiempo, así que el contador se
 * bloquea dentro de una transacción (`lockForUpdate`). El índice único
 * `(cola_id, fecha)` es la segunda red: aunque dos procesos intenten crear la
 * fila del día a la vez, solo una queda.
 */
class GeneradorDeTurnos
{
    /**
     * Siguiente turno de la cola, ya formateado.
     */
    public function siguiente(Cola $cola, ?CarbonInterface $fecha = null): string
    {
        return $cola->formatearTurno($this->siguienteConsecutivo($cola, $fecha));
    }

    /**
     * El número crudo, sin prefijo ni ceros.
     */
    public function siguienteConsecutivo(Cola $cola, ?CarbonInterface $fecha = null): int
    {
        $dia = ($fecha ?? now())->toDateString();

        return DB::transaction(function () use ($cola, $dia): int {
            // `insertOrIgnore` deja la fila del día lista sin reventar si otro
            // proceso se adelantó: el índice único decide.
            DB::table('contadores_turno')->insertOrIgnore([
                'cola_id' => $cola->getKey(),
                'fecha' => $dia,
                'ultimo' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $contador = ContadorTurno::query()
                ->where('cola_id', $cola->getKey())
                ->where('fecha', $dia)
                ->lockForUpdate()
                ->firstOrFail();

            $contador->increment('ultimo');

            return (int) $contador->ultimo;
        });
    }

    /**
     * Cuántos turnos lleva entregados la cola en el día, sin consumir uno.
     */
    public function entregadosHoy(Cola $cola, ?CarbonInterface $fecha = null): int
    {
        return (int) ContadorTurno::query()
            ->where('cola_id', $cola->getKey())
            ->whereDate('fecha', ($fecha ?? now())->toDateString())
            ->value('ultimo');
    }
}
