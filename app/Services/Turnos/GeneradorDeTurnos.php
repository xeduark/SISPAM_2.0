<?php

namespace App\Services\Turnos;

use App\Models\ContadorTurno;
use App\Models\Sede;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Entrega el siguiente turno de una sede: «0060».
 *
 * El consecutivo es **por sede y por día**, así que cada mañana vuelve a
 * empezar en 1.
 *
 * ## Por qué numera la sede y no la cola
 *
 * Mientras el turno llevaba el prefijo de la cola, que cada una contara aparte
 * estaba bien: `A-0060` y `B-0060` eran distintos. Sin prefijo dejan de serlo,
 * y `TicketConsultaDb::porTurnoDeHoy()` busca el turno dentro de la sede: con
 * dos colas contando por su lado se encontraría dos tickets `0060` el mismo
 * día y no sabría cuál es.
 *
 * La cola se sigue escogiendo igual —alto costo va a la suya—, pero ya no
 * numera.
 *
 * ## Por qué hay una tabla de contadores
 *
 * Varios orientadores piden turno al mismo tiempo, así que el contador se
 * bloquea dentro de una transacción (`lockForUpdate`). El índice único
 * `(sede_id, fecha)` es la segunda red: aunque dos procesos intenten crear la
 * fila del día a la vez, solo una queda.
 */
class GeneradorDeTurnos
{
    /** Dígitos del consecutivo: «0060». Es como sale en el ticket impreso. */
    public const DIGITOS = 4;

    /**
     * Siguiente turno de la sede, ya formateado.
     */
    public function siguiente(Sede $sede, ?CarbonInterface $fecha = null): string
    {
        return static::formatear($this->siguienteConsecutivo($sede, $fecha));
    }

    /**
     * El consecutivo como lo ve el paciente: cuatro dígitos con ceros delante.
     *
     * Pasado el 9999 sigue creciendo («10000») en vez de cortarse: es mejor un
     * turno de cinco cifras que dos pacientes con el mismo número.
     */
    public static function formatear(int $consecutivo): string
    {
        return str_pad((string) $consecutivo, self::DIGITOS, '0', STR_PAD_LEFT);
    }

    /**
     * El número crudo, sin ceros delante.
     */
    public function siguienteConsecutivo(Sede $sede, ?CarbonInterface $fecha = null): int
    {
        $dia = ($fecha ?? now())->toDateString();

        return DB::transaction(function () use ($sede, $dia): int {
            // `insertOrIgnore` deja la fila del día lista sin reventar si otro
            // proceso se adelantó: el índice único decide.
            DB::table('contadores_turno')->insertOrIgnore([
                'sede_id' => $sede->getKey(),
                'fecha' => $dia,
                'ultimo' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $contador = ContadorTurno::query()
                ->where('sede_id', $sede->getKey())
                ->where('fecha', $dia)
                ->lockForUpdate()
                ->firstOrFail();

            $contador->increment('ultimo');

            return (int) $contador->ultimo;
        });
    }

    /**
     * Cuántos turnos lleva entregados la sede en el día, sin consumir uno.
     */
    public function entregadosHoy(Sede $sede, ?CarbonInterface $fecha = null): int
    {
        return (int) ContadorTurno::query()
            ->where('sede_id', $sede->getKey())
            ->whereDate('fecha', ($fecha ?? now())->toDateString())
            ->value('ultimo');
    }
}
