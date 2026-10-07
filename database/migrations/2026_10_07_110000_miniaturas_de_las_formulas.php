<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Miniaturas de las fórmulas, para que la galería cargue.
 *
 * Una visita con diez hojas de 2000 px son unos 4 MB de imágenes. En el 4G
 * del mostrador eso es la diferencia entre abrir la ficha y quedarse mirando
 * el cargador. La miniatura de 400 px pesa unos 40 KB.
 *
 * Se guarda la ruta y no se regenera nada en esta migración: las miniaturas
 * se crean **la primera vez que alguien las pide**, así que los soportes que
 * ya existen quedan cubiertos sin un comando de relleno. Ver
 * `Services\Soportes\GeneradorDeMiniaturas`.
 *
 * Queda en null para los PDF: GD no los lee, y la galería les pone un ícono.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('soportes', function (Blueprint $table) {
            // Ruta en el mismo disco privado que el original. Nunca pública:
            // una miniatura de una fórmula sigue siendo un dato de salud.
            $table->string('miniatura')->nullable()->after('mime');
        });
    }

    public function down(): void
    {
        Schema::table('soportes', function (Blueprint $table) {
            $table->dropColumn('miniatura');
        });
    }
};
