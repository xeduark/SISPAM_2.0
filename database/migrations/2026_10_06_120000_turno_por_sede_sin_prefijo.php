<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El turno pierde el prefijo de la cola: de «A-0060» a «0060».
 *
 * ## Por qué el contador tiene que cambiar de dueño
 *
 * Mientras el turno llevaba el prefijo, que cada cola contara aparte estaba
 * bien: `A-0060` y `B-0060` son distintos. **Sin prefijo dejan de serlo.** Dos
 * colas de la misma sede sacarían las dos un `0060` el mismo día, y
 * `TicketConsultaDb::porTurnoDeHoy()` busca el turno dentro de la sede: se
 * encontraría con dos tickets y no sabría cuál es.
 *
 * Por eso `contadores_turno` pasa de ser **por cola y día** a **por sede y
 * día**. La cola se sigue escogiendo igual —alto costo va a la suya— pero ya
 * no numera: numera la sede.
 *
 * ## Qué pasa con los contadores que ya existían
 *
 * Si una sede tenía dos colas contando por su lado, al juntarlas se conserva
 * **el mayor** de los dos consecutivos. Quedarse con el menor repetiría
 * números que ya se entregaron en papel.
 *
 * ## Los tickets ya emitidos no se tocan
 *
 * Conservan su turno viejo (`A-0001`) y su número. El índice único nuevo
 * (`sede_id`, `fecha`, `turno`) los admite sin problema, porque `A-0001` y
 * `B-0001` siguen siendo dos cadenas distintas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `hasColumn` para poder repetirla si se quedó a medias: MySQL no
        // deshace los cambios de estructura si algo revienta en el camino.
        if (! Schema::hasColumn('contadores_turno', 'sede_id')) {
            Schema::table('contadores_turno', function (Blueprint $table) {
                $table->foreignId('sede_id')->nullable()->after('id')
                    ->constrained('sedes')->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('contadores_turno', 'cola_id')) {
            $this->juntarContadoresPorSede();

            Schema::table('contadores_turno', function (Blueprint $table) {
                // La llave foránea primero: MySQL se apoya en el índice único
                // para sostenerla y no deja borrarlo mientras exista.
                $table->dropForeign(['cola_id']);
                $table->dropUnique(['cola_id', 'fecha']);
                $table->dropColumn('cola_id');
                // La clave del reinicio diario y del bloqueo al pedir turno.
                $table->unique(['sede_id', 'fecha']);
            });
        }

        Schema::table('tickets', function (Blueprint $table) {
            // El turno ya no es único por cola: lo es por sede y día.
            $table->dropUnique(['sede_id', 'cola_id', 'fecha', 'turno']);
            $table->unique(['sede_id', 'fecha', 'turno']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['sede_id', 'fecha', 'turno']);
            $table->unique(['sede_id', 'cola_id', 'fecha', 'turno']);
        });

        Schema::table('contadores_turno', function (Blueprint $table) {
            $table->foreignId('cola_id')->nullable()->after('id')
                ->constrained('colas')->cascadeOnDelete();
        });

        // Volver atrás reparte el contador de la sede a su primera cola. Es lo
        // mejor que se puede hacer: al juntarlos se perdió de cuál venía cada
        // uno, y lo que importa es no repetir números ya entregados.
        foreach (DB::table('contadores_turno')->get() as $fila) {
            DB::table('contadores_turno')->where('id', $fila->id)->update([
                'cola_id' => DB::table('colas')->where('sede_id', $fila->sede_id)->orderBy('id')->value('id'),
            ]);
        }

        DB::table('contadores_turno')->whereNull('cola_id')->delete();

        Schema::table('contadores_turno', function (Blueprint $table) {
            // Igual que al subir: la foránea antes que el índice que la sostiene.
            $table->dropForeign(['sede_id']);
            $table->dropUnique(['sede_id', 'fecha']);
            $table->dropColumn('sede_id');
            $table->unique(['cola_id', 'fecha']);
        });
    }

    /**
     * Una fila por sede y fecha, con el mayor consecutivo de sus colas.
     */
    private function juntarContadoresPorSede(): void
    {
        $filas = DB::table('contadores_turno')
            ->join('colas', 'colas.id', '=', 'contadores_turno.cola_id')
            ->select('contadores_turno.id', 'contadores_turno.fecha', 'contadores_turno.ultimo', 'colas.sede_id')
            ->get();

        if ($filas->isEmpty()) {
            return;
        }

        $sobrevive = [];

        foreach ($filas as $fila) {
            $clave = $fila->sede_id.'|'.$fila->fecha;

            if (! isset($sobrevive[$clave]) || $fila->ultimo > $sobrevive[$clave]->ultimo) {
                $sobrevive[$clave] = $fila;
            }
        }

        foreach ($sobrevive as $fila) {
            DB::table('contadores_turno')->where('id', $fila->id)->update(['sede_id' => $fila->sede_id]);
        }

        DB::table('contadores_turno')
            ->whereNotIn('id', array_map(fn ($fila): int => (int) $fila->id, array_values($sobrevive)))
            ->delete();
    }
};
