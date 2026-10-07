<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 de transcripción (revisar y confirmar).
 * - Cada medicamento dice de qué fórmula de la imagen es: nunca se mezclan.
 * - El rechazo guarda su motivo (de una lista) aparte del error de lectura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcripcion_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('formula')->default(1)->after('transcripcion_id');
        });

        Schema::table('transcripciones', function (Blueprint $table) {
            $table->string('motivo_rechazo', 30)->nullable()->after('error');
            $table->string('detalle_rechazo')->nullable()->after('motivo_rechazo');
        });
    }

    public function down(): void
    {
        Schema::table('transcripcion_items', fn (Blueprint $table) => $table->dropColumn('formula'));
        Schema::table('transcripciones', fn (Blueprint $table) => $table->dropColumn(['motivo_rechazo', 'detalle_rechazo']));
    }
};
