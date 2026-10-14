<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ControlaPermisos;
use App\Filament\Resources\UserResource\Pages;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Services\Authentik\AprovisionarEnAuthentik;
use App\Services\Authentik\ErrorDeAuthentik;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
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
                    ->helperText('Es su usuario en Authentik. Al guardar, SISPAM le crea allá la cuenta si no la tiene.')
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
                /*
                 * La contraseña con la que entra en Authentik. SISPAM se la pasa
                 * a Authentik al crearle la cuenta y no la guarda: no es una
                 * columna de `users` (`CreateUser` la saca antes de guardar).
                 * Para cambiarla después: acción «Cambiar contraseña».
                 */
                static::campoClave('clave_authentik', 'Contraseña')
                    ->helperText('Con esta entra en Authentik. SISPAM se la pasa a Authentik y no la guarda. Si ya tenía cuenta allá que no creó SISPAM, conserva la suya.')
                    ->visible(fn (string $operation): bool => $operation === 'create' && app(AprovisionarEnAuthentik::class)->configurado()),
                // Con su código: «PREMIUM PLAZA (PRP)». Buscar «PRP» la encuentra.
                Forms\Components\Select::make('sede_id')
                    ->label('Sede actual')
                    ->helperText('Dónde está trabajando ahora. Si tiene varias asignadas, puede moverse desde la barra superior.')
                    ->options(fn (): array => Sede::opciones())
                    ->getOptionLabelUsing(fn ($value): ?string => Sede::find($value)?->etiqueta)
                    ->required()
                    ->searchable()
                    ->preload(),
                /*
                 * En cuáles puede trabajar. Con una sola, el selector de la
                 * barra ni se muestra y la persona trabaja como siempre.
                 *
                 * Es lo que decide a qué datos de salud llega: cada sede ve
                 * sus pacientes, sus tickets y sus fórmulas, así que agregar
                 * una aquí es ampliar el acceso de alguien.
                 */
                Forms\Components\Select::make('sedes')
                    ->label('Sedes donde puede trabajar')
                    ->helperText('Agregar una amplía lo que esta persona puede ver y atender. Los administradores se mueven por todas sin necesidad de esto.')
                    ->relationship('sedes', 'nombre')
                    ->getOptionLabelFromRecordUsing(fn (Sede $record): string => $record->etiqueta)
                    ->multiple()
                    ->preload()
                    ->searchable()
                    // Una cuenta compartida queda fija en su sede.
                    ->hidden(fn (?User $record, Get $get): bool => (bool) $record?->es_administrador || (bool) $get('cuenta_compartida'))
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('activo')
                    ->label('Usuario activo')
                    ->default(true),
                // Solo un administrador puede nombrar a otro: si no, quien tenga el
                // módulo Usuarios podría darse todos los permisos.
                Forms\Components\Toggle::make('es_administrador')
                    ->label('Administrador')
                    ->helperText(fn (Get $get): string => $get('cuenta_compartida')
                        ? 'Una cuenta compartida no puede ser administradora: no se sabe quién está detrás.'
                        : 'Ve todos los módulos y administra la matriz de permisos.')
                    ->disabled(fn (Get $get): bool => (bool) $get('cuenta_compartida'))
                    ->visible(fn (): bool => (bool) auth()->user()?->es_administrador),
                /*
                 * La usan varias personas de una sede (las de consulta). Decide
                 * su rol y su sede, así que, como el interruptor de
                 * administrador, solo lo ve un administrador.
                 */
                Forms\Components\Toggle::make('cuenta_compartida')
                    ->label('Cuenta compartida')
                    ->helperText('La usan varias personas de la sede. Siempre tiene el rol CONSULTA y solo ese, queda fija en su sede, no puede ser administradora y se cierra sola tras 30 minutos sin uso.')
                    ->live()
                    // Se ve de una vez en el campo Roles; al guardar, el modelo lo impone.
                    ->afterStateUpdated(fn (bool $state, Set $set) => $state ? $set('roles', [Rol::CONSULTA]) : null)
                    ->visible(fn (): bool => (bool) auth()->user()?->es_administrador),
                /*
                 * Los roles son sus grupos en Authentik: al guardar, SISPAM se
                 * los pone allá (`AprovisionarEnAuthentik`), y al iniciar sesión
                 * se vuelven a copiar de allá. Solo los asigna un administrador,
                 * como el interruptor de arriba: un rol da permisos, así que
                 * quien tenga el módulo Usuarios podría darse los que quisiera.
                 */
                Forms\Components\Select::make('roles')
                    ->label('Roles')
                    ->helperText(fn (Get $get): string => $get('cuenta_compartida')
                        ? 'Una cuenta compartida siempre tiene el rol CONSULTA, y solo ese. Lo que puede hacer se ajusta en Roles y permisos. Al guardar, SISPAM le asigna ese grupo en Authentik.'
                        : 'Son sus grupos en Authentik: al guardar, SISPAM se los asigna allá. Los grupos de otras aplicaciones no se tocan.')
                    // Fijo en CONSULTA: no se manda, y `User::booted()` lo pone al guardar.
                    ->disabled(fn (Get $get): bool => (bool) $get('cuenta_compartida'))
                    ->multiple()
                    ->options(fn (): array => Rol::query()->orderBy('nombre')->pluck('nombre', 'nombre')->all())
                    // En `roles` también quedan grupos de otras aplicaciones, copiados
                    // de Authentik al ingresar: aquí solo se ven y se guardan los de SISPAM.
                    ->formatStateUsing(fn (?array $state): array => AprovisionarEnAuthentik::rolesDeSispam($state))
                    ->dehydrateStateUsing(fn (?array $state): array => AprovisionarEnAuthentik::rolesDeSispam($state))
                    ->visible(fn (): bool => (bool) auth()->user()?->es_administrador)
                    ->columnSpanFull(),
                Forms\Components\Placeholder::make('roles_actuales')
                    ->label('Roles (grupos en Authentik)')
                    ->content(fn (?User $record): string => implode(', ', $record?->roles ?? []) ?: 'Se toman de Authentik al iniciar sesión.')
                    ->hidden(fn (string $operation): bool => $operation === 'create' || (bool) auth()->user()?->es_administrador),
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
                    ->formatStateUsing(fn (User $record): string => $record->sede?->etiqueta ?? '—')
                    ->sortable(),
                Tables\Columns\IconColumn::make('activo')
                    ->boolean(),
                Tables\Columns\TextColumn::make('roles')
                    ->label('Roles')
                    ->badge()
                    ->placeholder('—')
                    ->description(fn (User $record): ?string => match (true) {
                        $record->es_administrador => 'Administrador',
                        $record->cuenta_compartida => 'Cuenta compartida',
                        default => null,
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nombre', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('sede_id')
                    ->label('Sede')
                    ->options(fn (): array => Sede::opciones())
                    ->searchable()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),
            ])
            ->actions([
                static::accionCambiarClave(),
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
     * El campo de una contraseña para Authentik: oculta (con el ojo para
     * verla), y sin que el navegador le meta la del administrador.
     */
    public static function campoClave(string $nombre, string $etiqueta): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($nombre)
            ->label($etiqueta)
            ->password()
            ->revealable()
            ->required()
            ->minLength(AprovisionarEnAuthentik::CLAVE_MINIMA)
            ->maxLength(128)
            ->autocomplete('new-password');
    }

    /**
     * Cambiarle la contraseña de Authentik: la escribe el administrador.
     * Solo un administrador, y solo para cuentas que creó SISPAM
     * (`AprovisionarEnAuthentik::cambiarClave()`); si todavía no tiene
     * cuenta, se le crea con esa contraseña. Sirve en la lista
     * (`Tables\Actions\Action`) y en la edición (`Actions\Action`).
     *
     * @param  class-string<Tables\Actions\Action|Actions\Action>  $clase
     */
    public static function accionCambiarClave(string $clase = Tables\Actions\Action::class): Tables\Actions\Action|Actions\Action
    {
        return $clase::make('cambiarClave')
            ->label('Cambiar contraseña')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->es_administrador && app(AprovisionarEnAuthentik::class)->configurado())
            ->modalHeading(fn (User $record): string => "Cambiar la contraseña de {$record->nombre_completo}")
            ->modalDescription('Es la contraseña con la que entra en Authentik. Solo se puede cambiar aquí si esa cuenta la creó SISPAM; si todavía no tiene cuenta, se le crea con esta.')
            ->form([static::campoClave('clave', 'Contraseña nueva')])
            ->modalSubmitActionLabel('Cambiar contraseña')
            ->action(function (User $record, array $data): void {
                try {
                    app(AprovisionarEnAuthentik::class)->cambiarClave($record, $data['clave'])->notificacion($record)->send();
                } catch (ErrorDeAuthentik $e) {
                    Notification::make()
                        ->danger()
                        ->persistent()
                        ->title('No se cambió la contraseña')
                        ->body($e->getMessage())
                        ->send();
                }
            });
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
