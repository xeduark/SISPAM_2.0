<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El rol CONSULTA también ve los programas especiales y RIAS
 * (`consultar_paciente.ver_programas`).
 *
 * Las cuentas de las sedes veían solo lo básico. Desde la v1.1.2 ven además
 * los programas y RIAS que manda Savia, sin el resto de los datos de salud
 * (eso sigue siendo `ver_completo`).
 *
 * `RolSeeder` usa `firstOrCreate` y no toca los roles que ya existen; por eso
 * a las bases que ya tienen el rol, producción incluida, la acción llega por
 * aquí, igual que en `2026_10_13_110000_ver_todo_lo_de_savia`. Así el
 * administrador se la puede quitar desde Roles y permisos sin tocar código.
 *
 * Solo agrega, y solo a CONSULTA si ya tiene `ver`: nunca quita acciones ni
 * reemplaza el arreglo de permisos. Correrla de nuevo no cambia nada. No crea
 * el rol: si no existe, no hace nada (lo crea `RolSeeder`).
 */
return new class extends Migration
{
    private const MODULO = 'consultar_paciente';

    private const ACCION = 'ver_programas';

    private const ROL = 'CONSULTA';

    public function up(): void
    {
        foreach (DB::table('roles')->where('nombre', self::ROL)->get() as $rol) {
            $permisos = $this->permisos($rol->permisos);
            $acciones = array_values((array) ($permisos[self::MODULO] ?? []));

            if (! in_array('ver', $acciones, true) || in_array(self::ACCION, $acciones, true)) {
                continue;
            }

            $permisos[self::MODULO] = [...$acciones, self::ACCION];

            $this->guardar($rol->id, $permisos);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->where('nombre', self::ROL)->get() as $rol) {
            $permisos = $this->permisos($rol->permisos);
            $acciones = array_values((array) ($permisos[self::MODULO] ?? []));

            if (! in_array(self::ACCION, $acciones, true)) {
                continue;
            }

            $permisos[self::MODULO] = array_values(array_diff($acciones, [self::ACCION]));

            $this->guardar($rol->id, $permisos);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function permisos(?string $json): array
    {
        $permisos = json_decode((string) $json, true);

        return is_array($permisos) ? $permisos : [];
    }

    /**
     * @param  array<string, list<string>>  $permisos
     */
    private function guardar(int $id, array $permisos): void
    {
        DB::table('roles')->where('id', $id)->update([
            'permisos' => json_encode($permisos, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
};
