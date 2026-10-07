<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cada medicamento se marca «revisado» contra la fórmula original antes de confirmar. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcripcion_items', function (Blueprint $table) {
            $table->boolean('revisado')->default(false)->after('alertas');
        });
    }

    public function down(): void
    {
        Schema::table('transcripcion_items', fn (Blueprint $table) => $table->dropColumn('revisado'));
    }
};
