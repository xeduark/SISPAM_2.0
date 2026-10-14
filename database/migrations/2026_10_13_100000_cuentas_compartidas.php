<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas que usan varias personas de una misma sede.
 *
 * ## Por qué hace falta marcarlas
 *
 * Con una cuenta personal la auditoría ya dice quién hizo qué. Con una
 * compartida no: «ConsultaPRP consultó tal documento» no dice cuál de las
 * personas de la sede estaba frente al equipo. Por eso, al entrar con una de
 * estas cuentas, la persona escribe su nombre y su documento, y **eso queda
 * en cada línea de la auditoría** junto a la cuenta y la sede.
 *
 * La marca también fija la cuenta en su sede (sin selector), le impide ser
 * administradora y cierra la sesión tras un rato sin uso.
 *
 * ## Las columnas de la auditoría
 *
 * `persona_nombre` y `persona_documento` son de quien dijo estar usando la
 * cuenta. Quedan vacías en las cuentas personales, que ya se identifican
 * solas. Se usan solo para la auditoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('cuenta_compartida')->default(false)->after('es_administrador');
        });

        Schema::table('auditorias', function (Blueprint $table) {
            $table->string('persona_nombre', 160)->nullable()->after('usuario_documento');
            $table->string('persona_documento', 40)->nullable()->after('persona_nombre');
        });
    }

    public function down(): void
    {
        Schema::table('auditorias', function (Blueprint $table) {
            $table->dropColumn(['persona_nombre', 'persona_documento']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cuenta_compartida');
        });
    }
};
