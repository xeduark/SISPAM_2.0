<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matriz de permisos por módulo. Cada rol (nombre del grupo en Authentik)
 * guarda qué acciones puede hacer en cada módulo, p. ej.
 * {"pacientes": ["ver", "crear"], "orientacion": ["usar"]}.
 *
 * El administrador se marca en SISPAM, no en Authentik: ve todo y es quien
 * edita la matriz, así nunca queda el sistema sin quien lo administre.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('es_administrador')->default(false)->after('activo');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80)->unique();
            $table->json('permisos')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('es_administrador');
        });
    }
};
