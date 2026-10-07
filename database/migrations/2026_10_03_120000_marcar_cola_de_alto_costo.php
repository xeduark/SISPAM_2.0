<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Relleno para las instalaciones que ya tenían colas.
 *
 * `colas.atiende_alto_costo` llegó después de que `ColaSeeder` creara las
 * colas, y el seeder usa `firstOrCreate`: no pisa lo que ya existe, así que
 * las colas de alto costo quedaron sin marcar y todos los tickets se irían a
 * la cola general.
 *
 * Se marca la cola que el seeder creó justamente para eso, y solo en las
 * sedes que todavía no tengan ninguna marcada: así no se toca nada que el
 * administrador haya configurado a mano.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $sedesSinMarcar = DB::table('colas')
            ->select('sede_id')
            ->groupBy('sede_id')
            ->havingRaw('SUM(atiende_alto_costo) = 0')
            ->pluck('sede_id');

        if ($sedesSinMarcar->isEmpty()) {
            return;
        }

        DB::table('colas')
            ->whereIn('sede_id', $sedesSinMarcar)
            ->where('nombre', 'Alto costo y oncológicos')
            ->update(['atiende_alto_costo' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Es un relleno de datos: deshacerlo dejaría las sedes peor que antes.
    }
};
