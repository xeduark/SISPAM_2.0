<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las cuentas compartidas que ya existen quedan con el rol CONSULTA, y solo ese.
 *
 * En producción entraban sin él: los roles se copian de los grupos de
 * Authentik en cada ingreso, y esas cuentas no estaban en el grupo CONSULTA.
 * Llegaban al Escritorio sin nada en el menú.
 *
 * Desde la v1.0.1 eso ya no depende de Authentik ni de que alguien lo asigne:
 * `User::booted()` se lo pone al guardar y `User::rolesEfectivos()` lo impone
 * al leer los permisos. Esta migración solo pone al día lo que está guardado,
 * para que Usuarios muestre lo que de verdad cuenta.
 *
 * El grupo en Authentik se les asigna la próxima vez que se guarden en
 * Usuarios. Para entrar ya no hace falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('cuenta_compartida', true)
            ->update(['roles' => json_encode(['CONSULTA'])]);
    }

    public function down(): void
    {
        // No se sabe qué tenían antes, y volver a dejarlas sin rol es justo el
        // problema que esto arregla.
    }
};
