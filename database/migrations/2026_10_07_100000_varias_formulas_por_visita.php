<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Varias fotos por visita.
 *
 * Una fórmula médica rara vez cabe en una sola foto: tiene anexos, va por
 * ambas caras o son dos hojas. Hasta ahora el paso Orientación recibía un
 * único archivo.
 *
 * ## Por qué un soporte por archivo, y no un JSON con varias rutas
 *
 * `soportes.ticket_id` ya existía y la doc ya decía que varias órdenes de la
 * misma visita pueden colgar del mismo ticket: la agrupación por visita ya
 * estaba hecha. Lo que faltaba era el orden.
 *
 * Y sobre todo: la ruta protegida (`soportes/{soporte}/orden-medica`) y la
 * auditoría trabajan **por soporte**. Un soporte por archivo deja el
 * controlador y el rastro intactos, y hace que quede registrado quién abrió
 * *cuál* foto. Un JSON con varias rutas obligaría a indexar la ruta y a
 * perder esa precisión.
 *
 * ## Lo que ya está guardado no se toca
 *
 * `pagina` entra con default 1, así que los soportes que ya existen quedan
 * como la primera —y única— hoja de su visita. `mime` queda en null: se llena
 * de ahí en adelante y quien lo lea debe tolerar el null.
 *
 * A propósito **no se guarda el nombre original del archivo**: los celulares
 * suben cosas como `formula_JUAN_PEREZ.pdf`, y eso metería identificación del
 * paciente en una columna que hoy no la tiene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('soportes', function (Blueprint $table) {
            // El orden en que el orientador dejó las hojas.
            $table->unsignedSmallInteger('pagina')->default(1)->after('orden_medica');
            // Para saber si es PDF o imagen sin tener que tocar el disco.
            $table->string('mime', 100)->nullable()->after('pagina');

            $table->index(['ticket_id', 'pagina']);
        });
    }

    public function down(): void
    {
        Schema::table('soportes', function (Blueprint $table) {
            $table->dropIndex(['ticket_id', 'pagina']);
            $table->dropColumn(['pagina', 'mime']);
        });
    }
};
