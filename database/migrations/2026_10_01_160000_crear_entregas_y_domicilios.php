<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cabecera de una atención de dispensación (presencial o domicilio).
 * El ticket vive en otro módulo: solo guardamos su número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_numero', 64)->index();
            $table->foreignId('paciente_id')->nullable()->constrained('pacientes')->nullOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->string('tipo', 20); // presencial | domicilio
            $table->string('estado', 20)->default('en_proceso'); // en_proceso | parcial | completada | anulada
            $table->string('receptor_nombre')->nullable();
            $table->string('receptor_documento', 40)->nullable();
            $table->string('receptor_parentesco', 80)->nullable();
            $table->string('firma_path')->nullable();
            $table->text('observaciones')->nullable();
            $table->string('facturacion_estado', 20)->default('pendiente'); // no_aplica | pendiente | marcada
            $table->string('factura_referencia', 80)->nullable();
            $table->timestamps();
        });

        Schema::create('entrega_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entrega_id')->constrained('entregas')->cascadeOnDelete();
            $table->string('ticket_item_id', 64)->nullable();
            $table->string('codigo', 64);
            $table->string('nombre');
            $table->decimal('cantidad_solicitada', 12, 2);
            $table->decimal('cantidad_entregada', 12, 2)->default(0);
            $table->string('unidad', 20)->default('UND');
            $table->string('resultado', 20); // entregado | faltante | pendiente
            $table->string('motivo')->nullable();
            $table->timestamps();
        });

        Schema::create('domicilio_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entrega_id')->constrained('entregas')->cascadeOnDelete();
            $table->string('telefono', 40)->nullable();
            $table->string('direccion')->nullable();
            $table->string('barrio', 120)->nullable();
            $table->string('ciudad', 120)->nullable();
            $table->string('indicaciones_entrega')->nullable();
            $table->string('estado', 30)->default('pendiente_envio');
            $table->string('referencia_externa', 80)->nullable();
            $table->text('novedad_detalle')->nullable();
            $table->timestamps();
        });

        Schema::create('domicilio_estado_historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domicilio_envio_id')->constrained('domicilio_envios')->cascadeOnDelete();
            $table->string('estado_anterior', 30)->nullable();
            $table->string('estado_nuevo', 30);
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nota')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domicilio_estado_historial');
        Schema::dropIfExists('domicilio_envios');
        Schema::dropIfExists('entrega_items');
        Schema::dropIfExists('entregas');
    }
};
