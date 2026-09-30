<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RolResource\Pages;
use App\Models\Rol;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Matriz de permisos: una fila por rol (grupo de Authentik) y una columna por
 * módulo. Solo la administran los administradores, para que nadie se dé
 * permisos a sí mismo.
 */
class RolResource extends Resource
{
    protected static ?string $model = Rol::class;

    protected static ?string $modelLabel = 'Rol';

    protected static ?string $pluralModelLabel = 'Roles y permisos';

    protected static ?string $navigationLabel = 'Roles y permisos';

    protected static ?string $navigationGroup = 'Administración';

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $slug = 'roles';

    public static function can(string $action, ?Model $record = null): bool
    {
        return (bool) auth()->user()?->es_administrador;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('nombre')
                    ->label('Nombre del rol')
                    ->helperText('Escríbelo igual al grupo en Authentik, p. ej. ORIENTADOR.')
                    ->required()
                    ->maxLength(80)
                    ->unique(ignoreRecord: true)
                    // Los grupos se comparan tal cual: se evita el error de mayúsculas.
                    ->dehydrateStateUsing(fn (string $state): string => mb_strtoupper(trim($state))),

                Forms\Components\Section::make('Permisos por módulo')
                    ->schema(collect(Rol::MODULOS)
                        ->map(fn (array $modulo, string $clave): Forms\Components\CheckboxList => Forms\Components\CheckboxList::make("permisos.{$clave}")
                            ->label($modulo['nombre'])
                            ->options($modulo['acciones'])
                            ->columns(4)
                            ->gridDirection('row')
                            ->bulkToggleable())
                        ->values()
                        ->all()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Rol')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                // Una columna por módulo: la tabla misma es la matriz.
                ...collect(Rol::MODULOS)
                    ->map(fn (array $modulo, string $clave): Tables\Columns\TextColumn => Tables\Columns\TextColumn::make("modulo_{$clave}")
                        ->label($modulo['nombre'])
                        ->state(fn (Rol $record): array => array_values(array_intersect_key(
                            $modulo['acciones'],
                            array_flip($record->permisos[$clave] ?? []),
                        )))
                        ->badge()
                        ->color('primary')
                        ->placeholder('Sin acceso'))
                    ->values()
                    ->all(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->modalWidth(MaxWidth::ThreeExtraLarge),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Aún no hay roles')
            ->emptyStateDescription('Crea un rol con el mismo nombre del grupo en Authentik y marca lo que puede hacer en cada módulo.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageRoles::route('/'),
        ];
    }
}
