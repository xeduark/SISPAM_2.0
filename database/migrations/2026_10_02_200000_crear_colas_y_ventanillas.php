<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colas de atención y ventanillas, siempre por sede.
 *
 * Cada cola tiene un prefijo corto (A, B, AC...) con el que se arma el turno
 * que ve el paciente: «A-023». El consecutivo se reinicia cada día y vive en
 * `contadores_turno`, con una fila por cola y fecha: así dos orientadores
 * atendiendo al mismo tiempo nunca sacan el mismo número.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('colas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->string('nombre', 80);
            // Con el que empieza el turno: A-023. Admite hasta 3 letras (AC, ONC...).
            $table->string('prefijo', 3);
            $table->string('descripcion', 160)->nullable();
            $table->boolean('activa')->default(true);
            // Orden en que se muestran las colas de una sede.
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->unique(['sede_id', 'prefijo']);
            $table->unique(['sede_id', 'nombre']);
        });

        Schema::create('ventanillas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sede_id')->constrained('sedes')->restrictOnDelete();
            $table->string('nombre', 60);
            $table->boolean('activa')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            // Una ventanilla atiende cualquier cola de su sede: quien llama elige
            // de cuál. Por eso no se amarra a una cola concreta.
            $table->unique(['sede_id', 'nombre']);
        });

        Schema::create('contadores_turno', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cola_id')->constrained('colas')->cascadeOnDelete();
            $table->date('fecha');
            $table->unsignedInteger('ultimo')->default(0);
            $table->timestamps();

            // La clave del reinicio diario y del bloqueo al pedir turno.
            $table->unique(['cola_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contadores_turno');
        Schema::dropIfExists('ventanillas');
        Schema::dropIfExists('colas');
    }
};
