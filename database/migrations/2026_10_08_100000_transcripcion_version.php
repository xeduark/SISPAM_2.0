<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fase 4: cada rectificación de una transcripción confirmada sube la versión (sale en la orden). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcripciones', function (Blueprint $table) {
            $table->unsignedSmallInteger('version')->default(1)->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('transcripciones', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
