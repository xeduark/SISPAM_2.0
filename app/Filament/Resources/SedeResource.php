<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ControlaPermisos;
use App\Filament\Resources\SedeResource\Pages;
use App\Models\Sede;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class SedeResource extends Resource
{
    use ControlaPermisos;

    protected static string $modulo = 'sedes';

    protected static ?string $model = Sede::class;

    protected static ?string $modelLabel = 'Sede';

    protected static ?string $pluralModelLabel = 'Sedes';

    protected static ?string $navigationLabel = 'Sedes';

    protected static ?string $navigationGroup = 'Administración';

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('nombre')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('direccion')
                    ->label('Dirección')
                    ->maxLength(255),
                Forms\Components\TextInput::make('telefono')
                    ->label('Teléfono')
                    ->tel()
                    ->maxLength(255),
                Forms\Components\Toggle::make('activa')
                    ->label('Sede activa')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombre')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('direccion')
                    ->label('Dirección')
                    ->searchable(),
                Tables\Columns\TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->searchable(),
                Tables\Columns\IconColumn::make('activa')
                    ->boolean(),
            ])
            ->defaultSort('nombre', 'asc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(fn (Tables\Actions\DeleteAction $action, Sede $record) => static::cancelarSiTieneUsuarios($action, [$record])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(fn (Tables\Actions\DeleteBulkAction $action, Collection $records) => static::cancelarSiTieneUsuarios($action, $records)),
                ]),
            ]);
    }

    /**
     * Impide eliminar sedes que tengan usuarios asociados. La FK users.sede_id
     * también lo restringe a nivel de base de datos.
     *
     * @param  iterable<Sede>  $sedes
     */
    public static function cancelarSiTieneUsuarios(MountableAction $action, iterable $sedes): void
    {
        $conUsuarios = collect($sedes)->filter(fn (Sede $sede): bool => $sede->users()->exists());

        if ($conUsuarios->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('No se puede eliminar')
            ->body('Las siguientes sedes tienen usuarios asociados: '.$conUsuarios->pluck('nombre')->join(', ').'.')
            ->danger()
            ->send();

        $action->cancel();
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
            'index' => Pages\ListSedes::route('/'),
            'create' => Pages\CreateSede::route('/create'),
            'edit' => Pages\EditSede::route('/{record}/edit'),
        ];
    }
}
