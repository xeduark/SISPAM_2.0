<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastro de quién hizo qué en SISPAM, consultable desde el panel y no solo
 * en los archivos de log.
 *
 * Qué campos de cada modelo se guardan lo decide una lista BLANCA
 * (`CAMPOS_AUDITADOS` en cada modelo): lo que no se declara no se registra.
 * Así un campo clínico nuevo nunca entra solo. Los datos de salud no van
 * aquí: del paciente solo queda su documento.
 *
 * Una auditoría no se edita ni se borra: por eso no lleva `updated_at`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('auditorias', function (Blueprint $table) {
            $table->id();

            // Quién. El nombre y el documento quedan copiados por si algún día
            // se elimina el usuario: la auditoría debe seguir siendo legible.
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('usuario_nombre', 160);
            $table->string('usuario_documento', 40)->nullable();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();

            // Qué
            $table->string('accion', 40);
            $table->string('entidad_tipo', 60)->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->string('descripcion', 255);

            // Solo los campos de la lista blanca: {"regimen": ["SUBSIDIADO", "CONTRIBUTIVO"]}
            $table->json('cambios')->nullable();

            // Desde dónde
            $table->string('ip', 45)->nullable();
            $table->string('navegador', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('accion');
            $table->index('created_at');
            $table->index(['entidad_tipo', 'entidad_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auditorias');
    }
};
