<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ControlaPermisos;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    use ControlaPermisos;

    protected static string $modulo = 'usuarios';

    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'Usuario';

    protected static ?string $pluralModelLabel = 'Usuarios';

    protected static ?string $navigationLabel = 'Usuarios';

    protected static ?string $navigationGroup = 'Administración';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('nombre')
                    ->required()
                    ->maxLength(100),
                Forms\Components\TextInput::make('apellido')
                    ->required()
                    ->maxLength(100),
                Forms\Components\TextInput::make('documento')
                    ->label('Documento de identidad')
                    ->helperText('Debe coincidir con el nombre de usuario en Authentik.')
                    ->required()
                    ->alphaNum()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('email')
                    ->label('Correo electrónico')
                    ->email()
                    ->nullable()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Forms\Components\Select::make('sede_id')
                    ->label('Sede')
                    ->relationship('sede', 'nombre')
                    ->required()
                    ->searchable()
                    ->preload(),
                Forms\Components\Toggle::make('activo')
                    ->label('Usuario activo')
                    ->default(true),
                // Solo un administrador puede nombrar a otro: si no, quien tenga el
                // módulo Usuarios podría darse todos los permisos.
                Forms\Components\Toggle::make('es_administrador')
                    ->label('Administrador')
                    ->helperText('Ve todos los módulos y administra la matriz de permisos.')
                    ->visible(fn (): bool => (bool) auth()->user()?->es_administrador),
                Forms\Components\Placeholder::make('roles')
                    ->label('Roles (grupos en Authentik)')
                    ->content(fn (?User $record): string => implode(', ', $record?->roles ?? []) ?: 'Se toman de Authentik al iniciar sesión.')
                    ->hiddenOn('create'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('documento')
                    ->label('Documento')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('nombre')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('apellido')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sede.nombre')
                    ->label('Sede')
                    ->sortable(),
                Tables\Columns\IconColumn::make('activo')
                    ->boolean(),
                Tables\Columns\TextColumn::make('roles')
                    ->label('Roles')
                    ->badge()
                    ->placeholder('—')
                    ->description(fn (User $record): ?string => $record->es_administrador ? 'Administrador' : null),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nombre', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('sede')
                    ->label('Sede')
                    ->relationship('sede', 'nombre')
                    ->searchable()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->hidden(fn (User $record): bool => static::esUsuarioActual($record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function (Tables\Actions\DeleteBulkAction $action, Collection $records): void {
                            if ($records->contains(fn (User $record): bool => static::esUsuarioActual($record))) {
                                Notification::make()
                                    ->title('No puedes eliminar tu propio usuario')
                                    ->danger()
                                    ->send();

                                $action->cancel();
                            }
                        }),
                ]),
            ])
            ->checkIfRecordIsSelectableUsing(fn (User $record): bool => ! static::esUsuarioActual($record));
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record?->nombre_completo;
    }

    /**
     * Un usuario no puede eliminarse a sí mismo.
     */
    public static function esUsuarioActual(User $record): bool
    {
        return $record->is(auth()->user());
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
