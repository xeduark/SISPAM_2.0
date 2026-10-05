<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El llamado del turno en la sala (fase 6).
 *
 * Dos decisiones que conviene leer antes de tocar esto:
 *
 * 1. **Llamar no cambia `tickets.estado`.** Ese campo es el ciclo de la
 *    fórmula (generado → alistado → entregado) y el módulo de entrega solo
 *    atiende `listo` y `parcial`. Si al llamar el ticket pasara a «llamado»,
 *    el paciente que acaba de ser llamado no se podría atender. Por eso el
 *    turno lleva su propio ciclo en `tickets.estado_sala`.
 * 2. **Cada llamado queda como fila**, no solo el último. Al paciente se le
 *    llama varias veces y hay que poder mostrar «intento 2» y saber quién
 *    llamó y desde cuál ventanilla. El último llamado se copia a
 *    `tickets.ventanilla_id`, `llamado_por` y `llamado_en`, que ya existían.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Ciclo del turno en la sala, aparte del estado de la fórmula.
            $table->string('estado_sala', 20)->default('en_espera')->after('estado');

            // Lo que consulta la pantalla de llamado: la espera de hoy en la sede.
            $table->index(['sede_id', 'fecha', 'estado_sala'], 'tickets_espera_index');
        });

        Schema::create('llamados', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->foreignId('cola_id')->nullable()->constrained('colas')->nullOnDelete();
            $table->foreignId('ventanilla_id')->nullable()->constrained('ventanillas')->nullOnDelete();
            $table->foreignId('llamado_por')->nullable()->constrained('users')->nullOnDelete();

            // Vuelta que va: 1 el primer llamado, 2 el siguiente…
            $table->unsignedSmallInteger('intento')->default(1);

            $table->timestamps();

            // La pantalla de la sala pide los últimos llamados de la sede.
            $table->index(['sede_id', 'created_at']);
            $table->index('ticket_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('llamados');

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_espera_index');
            $table->dropColumn('estado_sala');
        });
    }
};
