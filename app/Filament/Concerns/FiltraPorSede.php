<?php

namespace App\Filament\Concerns;

use App\Models\Sede;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cada quien ve lo de su sede.
 *
 * El recurso que lo use debe tener una columna `sede_id`. El administrador ve
 * todas las sedes; los demás usuarios, solo la suya, tanto en el listado como
 * al crear.
 */
trait FiltraPorSede
{
    public static function getEloquentQuery(): Builder
    {
        $consulta = parent::getEloquentQuery();
        $usuario = auth()->user();

        if ($usuario !== null && ! $usuario->es_administrador) {
            $consulta->where('sede_id', $usuario->sede_id);
        }

        return $consulta;
    }

    /**
     * Campo de sede: el administrador escoge, los demás quedan fijos en la suya.
     */
    protected static function campoSede(): Forms\Components\Select
    {
        $usuario = auth()->user();
        $esAdministrador = (bool) $usuario?->es_administrador;

        return Forms\Components\Select::make('sede_id')
            ->label('Sede')
            ->relationship('sede', 'nombre')
            ->default($usuario?->sede_id)
            ->required()
            ->searchable()
            ->preload()
            // Quien no es administrador trabaja en su sede y no puede moverlo.
            ->disabled(! $esAdministrador)
            ->dehydrated()
            ->helperText($esAdministrador ? null : 'Tu sede. Solo un administrador puede cambiarla.')
            ->options(fn (): array => $esAdministrador
                ? Sede::orderBy('nombre')->pluck('nombre', 'id')->all()
                : Sede::whereKey($usuario?->sede_id)->pluck('nombre', 'id')->all());
    }
}
