<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La acción «Ver todo lo que trae Savia» (`consultar_paciente.ver_completo`).
 *
 * ## Dos niveles en Consultar paciente
 *
 * Hasta ahora `consultar_paciente.ver` mostraba todo lo que responde Savia,
 * programas especiales y datos de salud incluidos. Desde aquí:
 *
 * - `ver` muestra **lo básico**: identificación, estado de la afiliación,
 *   régimen e IPS primaria. Es lo del rol CONSULTA, que usan cuentas
 *   compartidas.
 * - `ver_completo` muestra el resto, como antes.
 *
 * ## Nadie que ya consulta pierde lo que veía
 *
 * Se le suma `ver_completo` a **todo rol que ya tenga `ver`**, ORIENTADOR
 * incluido, así que siguen viendo lo mismo. `RolSeeder` usa `firstOrCreate` y
 * no toca los roles que ya existen; por eso el cambio llega por migración,
 * igual que en `2026_10_06_110000_dar_permiso_de_imprimir_al_orientador`.
 *
 * Solo agrega: nunca quita acciones ni reemplaza el arreglo de permisos.
 * Correrla de nuevo no cambia nada.
 *
 * CONSULTA queda por fuera aunque alguien ya lo hubiera creado a mano: su
 * razón de ser es ver solo lo básico. El rol lo crea `RolSeeder`.
 */
return new class extends Migration
{
    private const MODULO = 'consultar_paciente';

    private const ACCION = 'ver_completo';

    private const ROL_SOLO_BASICO = 'CONSULTA';

    public function up(): void
    {
        foreach (DB::table('roles')->where('nombre', '!=', self::ROL_SOLO_BASICO)->get() as $rol) {
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
        foreach (DB::table('roles')->get() as $rol) {
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
