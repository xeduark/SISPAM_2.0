<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Autoriza el recurso con la matriz de permisos: cada habilidad de Filament
 * se traduce a una acción del módulo `static::$modulo` (ver `Rol::MODULOS`).
 * Sin el permiso «ver» el módulo ni siquiera aparece en el menú.
 */
trait ControlaPermisos
{
    public static function can(string $action, ?Model $record = null): bool
    {
        $accion = match ($action) {
            'viewAny', 'view' => 'ver',
            'create', 'replicate' => 'crear',
            'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny' => 'eliminar',
            default => 'editar',
        };

        return (bool) auth()->user()?->puede(static::$modulo . '.' . $accion);
    }
}
