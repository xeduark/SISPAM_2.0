<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Toma de datos del orientador: cada vez que atiende a un paciente carga la
 * orden médica (foto o documento) e indica si es de alto costo u oncológico.
 * Cada carga queda como un soporte del paciente; el ticket se liga después.
 *
 * Los roles vienen de los grupos de Authentik y se copian en cada inicio de sesión.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('roles')->nullable()->after('activo');
        });

        Schema::create('soportes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();
            // Ruta en el disco privado `local`: son datos de salud, nunca públicos.
            $table->string('orden_medica');
            $table->boolean('alto_costo_oncologico');
            $table->foreignId('cargado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('soportes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('roles');
        });
    }
};
