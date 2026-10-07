<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ControlaPermisos;
use App\Filament\Concerns\FiltraPorSede;
use App\Filament\Resources\ColaResource\Pages;
use App\Models\Cola;
use App\Models\Sede;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Colas de atención por sede.
 *
 * El turno del paciente ya no lleva prefijo —es solo el consecutivo de la
 * sede, «0060»—, así que el prefijo de la cola quedó como su etiqueta corta:
 * es lo que distingue «Dispensación general (A)» de «Alto costo (B)» en el
 * filtro de Llamar turnos.
 */
class ColaResource extends Resource
{
    use ControlaPermisos, FiltraPorSede;

    protected static string $modulo = 'colas';

    protected static ?string $model = Cola::class;

    protected static ?string $modelLabel = 'Cola';

    protected static ?string $pluralModelLabel = 'Colas';

    protected static ?string $navigationLabel = 'Colas';

    protected static ?string $navigationGroup = 'Administración';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                static::campoSede(),
                Forms\Components\TextInput::make('nombre')
                    ->label('Nombre de la cola')
                    ->placeholder('Dispensación general')
                    ->required()
                    ->maxLength(80),
                Forms\Components\TextInput::make('prefijo')
                    ->label('Etiqueta corta')
                    ->helperText('Una a tres letras para distinguir la cola en las pantallas: «Dispensación general (A)». El turno del paciente ya no la lleva.')
                    ->required()
                    ->alpha()
                    ->maxLength(3)
                    ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state))),
                Forms\Components\TextInput::make('orden')
                    ->label('Orden en pantalla')
                    ->helperText('Menor primero.')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(999),
                Forms\Components\TextInput::make('descripcion')
                    ->label('Descripción')
                    ->maxLength(160)
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('activa')
                    ->label('Cola activa')
                    ->helperText('Una cola inactiva no entrega turnos nuevos.')
                    ->default(true),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sede.nombre')
                    ->label('Sede')
                    ->formatStateUsing(fn ($record): string => $record->sede?->etiqueta ?? '—')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Cola')
                    ->description(fn (Cola $record): ?string => $record->descripcion)
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('prefijo')
                    ->label('Etiqueta')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('orden')
                    ->label('Orden')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('activa')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->defaultSort('orden')
            ->filters([
                Tables\Filters\SelectFilter::make('sede_id')
                    ->label('Sede')
                    ->options(fn (): array => Sede::opciones())
                    ->visible(fn (): bool => (bool) auth()->user()?->es_administrador),
                Tables\Filters\TernaryFilter::make('activa')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(fn (Tables\Actions\DeleteAction $action, Cola $record) => static::cancelarSiYaEntregoTurnos($action, $record)),
            ])
            ->bulkActions([]);
    }

    /**
     * Una cola que ya entregó turnos es historia: se desactiva, no se borra.
     *
     * Lo dicen sus tickets, no el contador: el contador pasó a ser de la sede,
     * así que una cola recién creada en una sede que ya atendió hoy aparecería
     * como usada sin haber entregado nada.
     */
    public static function cancelarSiYaEntregoTurnos(object $action, Cola $cola): void
    {
        if (! $cola->tickets()->exists()) {
            return;
        }

        Notification::make()
            ->title('No se puede eliminar')
            ->body("La cola «{$cola->nombre}» ya entregó turnos. Desactívala en vez de eliminarla, para no perder el historial.")
            ->danger()
            ->send();

        $action->cancel();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListColas::route('/'),
            'create' => Pages\CreateCola::route('/create'),
            'edit' => Pages\EditCola::route('/{record}/edit'),
        ];
    }
}
