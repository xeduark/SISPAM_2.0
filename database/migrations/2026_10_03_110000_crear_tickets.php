<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El ticket de una visita.
 *
 * Nace cuando el orientador registra al paciente con su orden médica, y lleva
 * dos identificadores con propósitos distintos:
 *
 * - `numero` (SP-LA30-20261003-A023) es **único en todo el sistema**. Es lo
 *   que busca el módulo de entrega, así que no puede repetirse entre sedes.
 * - `turno` (A-023) es corto, por sede y día. Es lo que se le dice al paciente
 *   y lo que sale en la pantalla de la sala.
 *
 * Los medicamentos los captura farmacia al alistar, no el orientador: por eso
 * el ticket nace sin items y en estado `generado`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();

            // Lo que busca entrega: único en todo el sistema.
            $table->string('numero', 40)->unique();
            // Lo que ve el paciente: corto, por sede y día.
            $table->string('turno', 16);
            $table->date('fecha');

            $table->foreignId('paciente_id')->constrained('pacientes')->restrictOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->foreignId('cola_id')->constrained('colas')->restrictOnDelete();

            $table->string('estado', 20)->default('generado');

            // Los preferenciales se llaman de primeras.
            $table->string('prioridad', 20)->default('normal');
            $table->string('motivo_prioridad', 40)->nullable();

            $table->boolean('alto_costo')->default(false);

            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();

            // Alistamiento: farmacia captura los medicamentos.
            $table->foreignId('alistado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('alistado_en')->nullable();

            // Llamado en sala (fase 6).
            $table->foreignId('ventanilla_id')->nullable()->constrained('ventanillas')->nullOnDelete();
            $table->foreignId('llamado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('llamado_en')->nullable();

            $table->timestamp('cerrado_en')->nullable();
            $table->string('motivo_anulacion', 160)->nullable();
            $table->text('observaciones')->nullable();

            $table->timestamps();

            // El turno no se repite en la misma cola el mismo día.
            $table->unique(['sede_id', 'cola_id', 'fecha', 'turno']);

            $table->index(['sede_id', 'estado']);
            $table->index(['fecha', 'estado']);
        });

        Schema::create('ticket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('codigo', 64);
            $table->string('nombre');
            $table->decimal('cantidad', 12, 2);
            $table->string('unidad', 20)->default('UND');
            $table->string('observacion')->nullable();
            $table->timestamps();
        });

        // La orden médica que originó el ticket. Varias órdenes de la misma
        // visita pueden colgar del mismo ticket.
        Schema::table('soportes', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('paciente_id')
                ->constrained('tickets')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('soportes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_id');
        });

        Schema::dropIfExists('ticket_items');
        Schema::dropIfExists('tickets');
    }
};
