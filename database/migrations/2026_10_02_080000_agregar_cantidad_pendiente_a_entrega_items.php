<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda por ítem la cantidad que queda pendiente (solicitada − entregada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entrega_items', function (Blueprint $table) {
            $table->decimal('cantidad_pendiente', 12, 2)->default(0)->after('cantidad_entregada');
        });

        // Rellena filas ya existentes.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('UPDATE entrega_items SET cantidad_pendiente = GREATEST(cantidad_solicitada - cantidad_entregada, 0)');
        } else {
            foreach (DB::table('entrega_items')->get() as $item) {
                DB::table('entrega_items')->where('id', $item->id)->update([
                    'cantidad_pendiente' => max(0, (float) $item->cantidad_solicitada - (float) $item->cantidad_entregada),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('entrega_items', function (Blueprint $table) {
            $table->dropColumn('cantidad_pendiente');
        });
    }
};
