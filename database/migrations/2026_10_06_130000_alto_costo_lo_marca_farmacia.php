<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El alto costo deja de separar filas: ahora es una etiqueta que pone farmacia.
 *
 * ## Por qué
 *
 * La marca «alto costo u oncológico» la respondía el orientador al registrar
 * la visita, y de ahí salía a qué cola iba el ticket. Pero **el orientador no
 * conoce los medicamentos**: los captura farmacia al alistar, después. Le
 * estábamos pidiendo una clasificación que no está en condiciones de hacer, y
 * encima nada la corregía más adelante.
 *
 * Ahora la pone farmacia, cuando ya tiene la fórmula a la vista, y sirve
 * **solo para identificar** el ticket: no decide fila ni turno.
 *
 * ## Qué hace esta migración
 *
 * Desactiva las colas de alto costo. Si nada vuelve a enrutar hacia ellas,
 * quedarían vacías para siempre pero visibles en Administración y en el filtro
 * de Llamar turnos, sin que nadie entienda por qué nunca tienen a nadie.
 *
 * **Se desactivan, no se borran**: es la regla del módulo de colas —una cola
 * es historia, y borrarla se llevaría por delante los turnos que dio—. Si
 * mañana se quiere volver a separar la fila, se reactivan desde la pantalla.
 *
 * `colas.atiende_alto_costo` se queda en la tabla: ya no enruta nada, pero
 * sigue diciendo cuál era la cola de alto costo de cada sede.
 *
 * ## Y `soportes.alto_costo_oncologico` pasa a ser nullable
 *
 * Esa columna guardaba la respuesta del orientador. Como la pregunta ya no
 * existe, un soporte nuevo no tiene nada que poner ahí. Se deja en `null`, que
 * quiere decir **«no se preguntó»**, en vez de escribir un `false` que sería
 * afirmar algo que nadie verificó. Lo que respondieron los orientadores hasta
 * hoy se conserva tal cual.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('colas')
            ->where('atiende_alto_costo', true)
            ->update(['activa' => false, 'updated_at' => now()]);

        Schema::table('soportes', function (Blueprint $table) {
            $table->boolean('alto_costo_oncologico')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('soportes')->whereNull('alto_costo_oncologico')->update(['alto_costo_oncologico' => false]);

        Schema::table('soportes', function (Blueprint $table) {
            $table->boolean('alto_costo_oncologico')->nullable(false)->default(false)->change();
        });

        DB::table('colas')
            ->where('atiende_alto_costo', true)
            ->update(['activa' => true, 'updated_at' => now()]);
    }
};
