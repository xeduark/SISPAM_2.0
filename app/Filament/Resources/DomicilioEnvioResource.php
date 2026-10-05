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
    public static function formularioCambioEstado(DomicilioEnvio $record): array
    {
        $opciones = $record->siguientesEstados();

        return [
            Forms\Components\Placeholder::make('estado_actual')
                ->label('Estado actual')
                ->content(DomicilioEnvio::estados()[$record->estado] ?? $record->estado),
            Forms\Components\Select::make('estado')
                ->label('Nuevo estado')
                ->options($opciones)
                ->required()
                ->helperText($opciones === []
                    ? 'Este envío no admite más cambios de estado.'
                    : 'Solo se muestran transiciones válidas.'),
            Forms\Components\Textarea::make('novedad_detalle')
                ->label('Novedad / observación')
                ->rows(3)
                ->helperText('Obligatorio en novedad o no entregado.'),
            Forms\Components\TextInput::make('nota_historial')
                ->label('Nota del historial')
                ->maxLength(255),
        ];
    }

    public static function aplicarCambioEstado(DomicilioEnvio $record, array $data): void
    {
        try {
            app(RegistrarEntrega::class)->cambiarEstadoDomicilio(
                $record,
                $data['estado'],
                auth()->user(),
                $data['nota_historial'] ?? null,
                $data['novedad_detalle'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            Notification::make()->danger()->title('Cambio no permitido')->body($e->getMessage())->send();

            return;
        }

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
                Infolists\Components\TextEntry::make('entrega.paciente.nombre_completo')->label('Paciente')->placeholder('—'),
                Infolists\Components\TextEntry::make('entrega.sede.nombre')->label('Sede de atención')->placeholder('—'),
                Infolists\Components\TextEntry::make('created_at')->label('Fecha de creación')->dateTime('d/m/Y H:i'),
                Infolists\Components\TextEntry::make('estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DomicilioEnvio::estados()[$state] ?? $state),
                Infolists\Components\TextEntry::make('updated_at')->label('Último cambio')->dateTime('d/m/Y H:i'),
                Infolists\Components\TextEntry::make('referencia_externa')->label('Referencia externa')->placeholder('—'),
                Infolists\Components\TextEntry::make('telefono')->label('Teléfono'),
                Infolists\Components\TextEntry::make('direccion')->label('Dirección'),
                Infolists\Components\TextEntry::make('barrio'),
                Infolists\Components\TextEntry::make('ciudad'),
                Infolists\Components\TextEntry::make('indicaciones_entrega')->label('Indicaciones')->columnSpanFull(),
                Infolists\Components\TextEntry::make('novedad_detalle')->label('Novedad')->columnSpanFull()->placeholder('—'),
            ])->columns(3),
            Infolists\Components\Section::make('Medicamentos')->schema([
                Infolists\Components\RepeatableEntry::make('entrega.items')->schema([
                    Infolists\Components\TextEntry::make('codigo'),
                    Infolists\Components\TextEntry::make('nombre'),
                    Infolists\Components\TextEntry::make('cantidad_solicitada')->label('Solicitada'),
                    Infolists\Components\TextEntry::make('cantidad_entregada')->label('Entregada'),
                    Infolists\Components\TextEntry::make('cantidad_pendiente')->label('Pendiente'),
                    Infolists\Components\TextEntry::make('motivo')->placeholder('—'),
                ])->columns(3),
            ]),
            Infolists\Components\Section::make('Historial de estados')->schema([
                Infolists\Components\RepeatableEntry::make('historial')->schema([
                    Infolists\Components\TextEntry::make('estado_anterior')
                        ->label('Anterior')
                        ->formatStateUsing(fn (?string $state): string => $state
                            ? (DomicilioEnvio::estados()[$state] ?? $state)
                            : '—'),
                    Infolists\Components\TextEntry::make('estado_nuevo')
                        ->label('Nuevo')
                        ->formatStateUsing(fn (string $state): string => DomicilioEnvio::estados()[$state] ?? $state),
                    Infolists\Components\TextEntry::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i'),
                    Infolists\Components\TextEntry::make('usuario.nombre_completo')->label('Usuario')->placeholder('—'),
                    Infolists\Components\TextEntry::make('nota')->label('Observación')->placeholder('—'),
                ])->columns(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('entrega.ticket_numero')->label('Ticket')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('entrega.paciente.nombre_completo')->label('Paciente')->toggleable(),
                Tables\Columns\TextColumn::make('entrega.sede.nombre')->label('Sede de atención')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DomicilioEnvio::estados()[$state] ?? $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('direccion')->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('telefono')->toggleable(),
                Tables\Columns\TextColumn::make('referencia_externa')->label('Ref. externa')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Creado')->dateTime('d/m/Y H:i')->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->label('Último cambio')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('estado')->options(DomicilioEnvio::estados()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ver detalle'),
                Tables\Actions\Action::make('cambiarEstado')
                    ->label('Cambiar estado')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (DomicilioEnvio $record): bool => (bool) auth()->user()?->puede('entrega.domicilio')
                        && $record->siguientesEstados() !== [])
                    ->fillForm(fn (DomicilioEnvio $record): array => [
                        'novedad_detalle' => $record->novedad_detalle,
                    ])
                    ->form(fn (DomicilioEnvio $record): array => self::formularioCambioEstado($record))
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
