<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ControlaPermisos;
use App\Filament\Concerns\FiltraPorSede;
use App\Filament\Resources\VentanillaResource\Pages;
use App\Models\Sede;
use App\Models\Ventanilla;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Puestos de atención por sede. Una ventanilla atiende cualquiera de las colas
 * de su sede: quien llama elige de cuál.
 */
class VentanillaResource extends Resource
{
    use ControlaPermisos, FiltraPorSede;

    protected static string $modulo = 'colas';

    protected static ?string $model = Ventanilla::class;

    protected static ?string $modelLabel = 'Ventanilla';

    protected static ?string $pluralModelLabel = 'Ventanillas';

    protected static ?string $navigationLabel = 'Ventanillas';

    protected static ?string $navigationGroup = 'Administración';

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                static::campoSede(),
                Forms\Components\TextInput::make('nombre')
                    ->label('Nombre')
                    ->placeholder('Ventanilla 1')
                    ->required()
                    ->maxLength(60),
                Forms\Components\TextInput::make('orden')
                    ->label('Orden en pantalla')
                    ->helperText('Menor primero.')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(999),
                Forms\Components\Toggle::make('activa')
                    ->label('Ventanilla activa')
                    ->helperText('Una ventanilla inactiva no aparece para llamar turnos.')
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
                    ->label('Ventanilla')
                    ->sortable()
                    ->searchable(),
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
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVentanillas::route('/'),
            'create' => Pages\CreateVentanilla::route('/create'),
            'edit' => Pages\EditVentanilla::route('/{record}/edit'),
        ];
    }
}
