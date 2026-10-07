<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le da `tickets.imprimir` al rol ORIENTADOR que ya está en la base.
 *
 * ## Por qué hace falta una migración y no basta el seeder
 *
 * `RolSeeder` usa `firstOrCreate`: si el rol ya existe **no lo toca**, y es a
 * propósito —así el seeder no pisa los permisos que el administrador haya
 * ajustado a mano—. Pero eso también significa que un permiso nuevo nunca
 * llega a las instalaciones que ya están andando. Esta migración cubre ese
 * hueco, igual que `2026_10_03_120000_marcar_cola_de_alto_costo` hizo con la
 * cola de alto costo.
 *
 * ## Solo agrega
 *
 * Nunca quita acciones ni reemplaza el arreglo de permisos: lee lo que haya,
 * le suma `imprimir` dentro del módulo `tickets` y lo vuelve a guardar. Si el
 * administrador ya se lo había dado, no cambia nada y correrla de nuevo
 * tampoco hace daño.
 *
 * DISPENSADOR no entra: imprimir queda atado a generar la visita.
 */
return new class extends Migration
{
    private const ROL = 'ORIENTADOR';

    private const MODULO = 'tickets';

    private const ACCION = 'imprimir';

    public function up(): void
    {
        $this->cambiar(fn (array $acciones): array => array_values(
            array_unique([...$acciones, self::ACCION]),
        ));
    }

    public function down(): void
    {
        $this->cambiar(fn (array $acciones): array => array_values(
            array_filter($acciones, fn (string $accion): bool => $accion !== self::ACCION),
        ));
    }

    /**
     * @param  callable(list<string>): list<string>  $ajustar
     */
    private function cambiar(callable $ajustar): void
    {
        $rol = DB::table('roles')->where('nombre', self::ROL)->first();

        // En una base nueva el rol todavía no existe: lo crea `RolSeeder`,
        // que ya lo deja con el permiso puesto.
        if ($rol === null) {
            return;
        }

        $permisos = json_decode((string) $rol->permisos, true);
        $permisos = is_array($permisos) ? $permisos : [];

        $acciones = $ajustar(array_values((array) ($permisos[self::MODULO] ?? [])));

        if ($acciones === []) {
            unset($permisos[self::MODULO]);
        } else {
            $permisos[self::MODULO] = $acciones;
        }

        DB::table('roles')->where('id', $rol->id)->update([
            'permisos' => json_encode($permisos, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
};
