<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transcripción de fórmulas.
 *
 * Una transcripción por cada orden médica cargada (`soportes`): así cada
 * medicamento queda amarrado a la fórmula de donde salió y nunca se mezclan
 * las de un mismo paciente. Detalle en docs/transcripcion.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcripciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('soporte_id')->unique()->constrained('soportes')->cascadeOnDelete();
            $table->foreignId('paciente_id')->constrained('pacientes')->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();

            $table->string('estado', 20)->default('en_cola');

            // Lo que leyó Google Vision. Dato de salud: se guarda cifrado.
            $table->longText('texto_ocr')->nullable();
            $table->string('verificacion_cedula', 20)->nullable();
            // Por qué falló la lectura (nunca el contenido de la fórmula).
            $table->string('error')->nullable();

            // IPS, médico, CIE-10, MIPRES, autorización, fechas: los llena la transcriptora.
            $table->json('formula')->nullable();

            $table->foreignId('tomada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('tomada_en')->nullable();
            $table->foreignId('confirmada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmada_en')->nullable();

            $table->timestamps();

            $table->index(['sede_id', 'estado']);
        });

        Schema::create('transcripcion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcripcion_id')->constrained('transcripciones')->cascadeOnDelete();

            // Tal como viene en la fórmula.
            $table->string('texto_prescrito');
            $table->string('concentracion', 80)->nullable();
            $table->string('posologia')->nullable();

            // La sugerencia del inventario.
            $table->string('codigo_inventario', 64)->nullable();
            $table->string('agrupador', 64)->nullable();
            $table->string('nombre_inventario')->nullable();
            $table->unsignedTinyInteger('similitud')->nullable();
            $table->json('alertas')->nullable();

            // Plan de tratamiento (reporte de 85 columnas de Savia).
            $table->decimal('cantidad_total', 12, 2)->nullable();
            $table->unsignedSmallInteger('duracion_dias')->nullable();
            $table->unsignedSmallInteger('meses')->nullable();
            $table->unsignedSmallInteger('entrega_mes')->default(1);
            $table->decimal('cantidad_mes', 12, 2)->nullable();

            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcripcion_items');
        Schema::dropIfExists('transcripciones');
    }
};
