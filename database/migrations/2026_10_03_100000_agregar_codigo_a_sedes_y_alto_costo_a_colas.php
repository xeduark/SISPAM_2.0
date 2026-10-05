<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos datos que necesita el ticket:
 *
 * - `sedes.codigo`: va dentro del número del ticket, para que al leerlo se
 *   sepa de qué sede salió (SP-LA30-20261003-A023).
 * - `colas.atiende_alto_costo`: marca cuál es la cola de alto costo y
 *   oncológicos de esa sede, para mandar ahí los tickets que el orientador
 *   marque. Así la regla es un dato configurable y no un prefijo adivinado.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sedes', function (Blueprint $table) {
            $table->string('codigo', 6)->nullable()->unique()->after('nombre');
        });

        Schema::table('colas', function (Blueprint $table) {
            $table->boolean('atiende_alto_costo')->default(false)->after('descripcion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('colas', function (Blueprint $table) {
            $table->dropColumn('atiende_alto_costo');
        });

        Schema::table('sedes', function (Blueprint $table) {
            $table->dropColumn('codigo');
        });
    }
};
