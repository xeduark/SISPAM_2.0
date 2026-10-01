<?php

namespace App\Filament\Resources;

use App\Models\DomicilioEnvio;
use App\Services\Entrega\RegistrarEntrega;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DomicilioEnvioResource extends Resource
{
    protected static ?string $model = DomicilioEnvio::class;

    protected static ?string $modelLabel = 'Envío a domicilio';

    protected static ?string $pluralModelLabel = 'Domicilios';

    protected static ?string $navigationLabel = 'Domicilios / Dómina';

    protected static ?string $navigationGroup = 'Entrega';

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->puede('entrega.domicilio')
            || (bool) auth()->user()?->puede('entrega.ver');
    }

    public static function can(string $action, ?Model $record = null): bool
    {
        return match ($action) {
            'viewAny', 'view' => (bool) auth()->user()?->puede('entrega.ver')
                || (bool) auth()->user()?->puede('entrega.domicilio'),
            default => false,
        };
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function formularioCambioEstado(): array
    {
        return [
            Forms\Components\Select::make('estado')
                ->label('Estado')
                ->options(DomicilioEnvio::estados())
                ->required(),
            Forms\Components\Textarea::make('novedad_detalle')
                ->label('Detalle de novedad')
                ->rows(3),
            Forms\Components\TextInput::make('nota_historial')
                ->label('Nota del cambio')
                ->maxLength(255),
        ];
    }

    public static function aplicarCambioEstado(DomicilioEnvio $record, array $data): void
    {
        app(RegistrarEntrega::class)->cambiarEstadoDomicilio(
            $record,
            $data['estado'],
            auth()->user(),
            $data['nota_historial'] ?? null,
            $data['novedad_detalle'] ?? null,
        );

        Notification::make()
            ->success()
            ->title('Estado de domicilio actualizado')
            ->send();
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Envío')->schema([
                Infolists\Components\TextEntry::make('entrega.ticket_numero')->label('Ticket'),
                Infolists\Components\TextEntry::make('estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DomicilioEnvio::estados()[$state] ?? $state),
                Infolists\Components\TextEntry::make('referencia_externa')->label('Ref. Dómina')->placeholder('—'),
                Infolists\Components\TextEntry::make('telefono'),
                Infolists\Components\TextEntry::make('direccion'),
                Infolists\Components\TextEntry::make('barrio'),
                Infolists\Components\TextEntry::make('ciudad'),
                Infolists\Components\TextEntry::make('indicaciones_entrega')->label('Indicaciones')->columnSpanFull(),
                Infolists\Components\TextEntry::make('novedad_detalle')->label('Novedad')->columnSpanFull()->placeholder('—'),
            ])->columns(3),
            Infolists\Components\Section::make('Historial')->schema([
                Infolists\Components\RepeatableEntry::make('historial')->schema([
                    Infolists\Components\TextEntry::make('estado_anterior')
                        ->formatStateUsing(fn (?string $state): string => $state
                            ? (DomicilioEnvio::estados()[$state] ?? $state)
                            : '—'),
                    Infolists\Components\TextEntry::make('estado_nuevo')
                        ->formatStateUsing(fn (string $state): string => DomicilioEnvio::estados()[$state] ?? $state),
                    Infolists\Components\TextEntry::make('usuario.nombre_completo')->label('Usuario')->placeholder('—'),
                    Infolists\Components\TextEntry::make('nota')->placeholder('—'),
                    Infolists\Components\TextEntry::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
                ])->columns(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('entrega.ticket_numero')->label('Ticket')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DomicilioEnvio::estados()[$state] ?? $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('ciudad')->toggleable(),
                Tables\Columns\TextColumn::make('direccion')->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('referencia_externa')->label('Ref. externa')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->label('Actualizado')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('estado')->options(DomicilioEnvio::estados()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('cambiarEstado')
                    ->label('Cambiar estado')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (): bool => (bool) auth()->user()?->puede('entrega.domicilio'))
                    ->fillForm(fn (DomicilioEnvio $record): array => [
                        'estado' => $record->estado,
                        'novedad_detalle' => $record->novedad_detalle,
                    ])
                    ->form(self::formularioCambioEstado())
                    ->action(fn (DomicilioEnvio $record, array $data) => self::aplicarCambioEstado($record, $data)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => DomicilioEnvioResource\Pages\ListDomicilioEnvios::route('/'),
            'view' => DomicilioEnvioResource\Pages\ViewDomicilioEnvio::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
