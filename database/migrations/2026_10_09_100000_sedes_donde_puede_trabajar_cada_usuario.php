<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * En qué sedes puede trabajar cada persona.
 *
 * Hasta ahora `users.sede_id` decía la sede de alguien y no se movía: para
 * cubrir otra sede un día había que pedirle a un administrador que la
 * cambiara. Esta tabla dice **en cuáles tiene permiso de estar**, y el
 * selector de la barra superior deja moverse entre ellas.
 *
 * ## `users.sede_id` no desaparece: es dónde está ahora
 *
 * Cambiar de sede actualiza esa columna, así que todo lo que ya la leía
 * —tickets, turnos, entrega, transcripción, inventario— sigue funcionando sin
 * tocarse. Esta tabla solo decide **qué opciones ofrece el selector**.
 *
 * ## Nadie pierde acceso al migrar
 *
 * A cada usuario que ya existe se le asigna la sede en la que está. Quien
 * tenga una sola no verá selector y seguirá exactamente igual que antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sede_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $table->timestamps();

            // Una persona no se asigna dos veces a la misma sede.
            $table->unique(['user_id', 'sede_id']);
        });

        // Lo que ya hay: cada quien queda habilitado en su sede actual.
        $ahora = now();

        $filas = DB::table('users')
            ->whereNotNull('sede_id')
            ->get(['id', 'sede_id'])
            ->map(fn ($usuario): array => [
                'user_id' => $usuario->id,
                'sede_id' => $usuario->sede_id,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])
            ->all();

        if ($filas !== []) {
            DB::table('sede_user')->insert($filas);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sede_user');
    }
};
