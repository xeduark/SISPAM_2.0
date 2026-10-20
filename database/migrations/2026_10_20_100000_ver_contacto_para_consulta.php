<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El rol CONSULTA también ve el teléfono y el correo que manda Savia
 * (`consultar_paciente.ver_contacto`).
 *
 * Desde la v1.1.3 las cuentas de las sedes ven, además de lo básico y los
 * programas, el celular, el teléfono fijo y el correo del afiliado según
 * Savia, sin el resto de los datos de salud (eso sigue siendo `ver_completo`).
 *
 * `RolSeeder` usa `firstOrCreate` y no toca los roles que ya existen; por eso
 * a las bases que ya tienen el rol, producción incluida, la acción llega por
 * aquí, igual que en `2026_10_16_100000_ver_programas_para_consulta`. Así el
 * administrador se la puede quitar desde Roles y permisos sin tocar código.
 *
 * Solo agrega, y solo a CONSULTA si ya tiene `ver`: nunca quita acciones ni
 * reemplaza el arreglo de permisos. Correrla de nuevo no cambia nada. No crea
 * el rol: si no existe, no hace nada (lo crea `RolSeeder`).
 */
return new class extends Migration
{
    private const MODULO = 'consultar_paciente';

    private const ACCION = 'ver_contacto';

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
