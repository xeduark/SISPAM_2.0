<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ControlaPermisos;
use App\Filament\Concerns\FiltraPorSede;
use App\Filament\Resources\TicketResource\Pages;
use App\Models\Cola;
use App\Models\Sede;
use App\Models\Ticket;
use App\Services\Tickets\AlistarTicket;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tickets de la sede. Se generan solos al registrar al paciente con su orden
 * médica, así que desde aquí no se crean a mano: se alistan, se consultan y
 * se anulan.
 */
class TicketResource extends Resource
{
    use ControlaPermisos, FiltraPorSede;

    protected static string $modulo = 'tickets';

    protected static ?string $model = Ticket::class;

    protected static ?string $modelLabel = 'Ticket';

    protected static ?string $pluralModelLabel = 'Tickets';

    protected static ?string $navigationLabel = 'Tickets';

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'numero';

    /** El ticket nace con el paciente, no desde esta pantalla. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Cuántos tickets están esperando en la sede: sale como aviso en el menú. */
    public static function getNavigationBadge(): ?string
    {
        $pendientes = static::getEloquentQuery()
            ->whereIn('estado', [Ticket::ESTADO_GENERADO, Ticket::ESTADO_EN_ALISTAMIENTO])
            ->whereDate('fecha', today())
            ->count();

        return $pendientes > 0 ? (string) $pendientes : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('turno')
                    ->label('Turno')
                    ->badge()
                    ->color(fn (Ticket $record): string => $record->esPreferencial() ? 'warning' : 'primary')
                    ->description(fn (Ticket $record): string => $record->numero)
                    ->searchable(['turno', 'numero'])
                    ->sortable(),
                Tables\Columns\TextColumn::make('paciente.documento_completo')
                    ->label('Paciente')
                    ->description(fn (Ticket $record): ?string => $record->paciente?->nombre_completo)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('paciente', fn (Builder $q) => $q
                            ->where('numero_documento', 'like', "%{$search}%")
                            ->orWhere('primer_apellido', 'like', "%{$search}%")
                            ->orWhere('primer_nombre', 'like', "%{$search}%"))),
                Tables\Columns\TextColumn::make('cola.nombre')
                    ->label('Cola')
                    ->sortable(),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Ticket::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state): string => Ticket::COLORES_ESTADO[$state] ?? 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('estado_sala')
                    ->label('En sala')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Ticket::ESTADOS_SALA[$state] ?? $state)
                    ->color(fn (string $state): string => Ticket::COLORES_SALA[$state] ?? 'gray')
                    ->description(fn (Ticket $record): ?string => $record->ventanilla?->nombre)
                    ->sortable(),
                Tables\Columns\IconColumn::make('alto_costo')
                    ->label('Alto costo')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-minus-small')
                    ->falseColor('gray'),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Medicamentos')
                    ->counts('items')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Generado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sede.nombre')
                    ->label('Sede')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Los preferenciales de primeras, y dentro de cada grupo por orden de llegada.
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(Ticket::ESTADOS)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('estado_sala')
                    ->label('En la sala')
                    ->options(Ticket::ESTADOS_SALA)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('cola_id')
                    ->label('Cola')
                    ->options(fn (): array => Cola::query()
                        ->when(! auth()->user()?->es_administrador, fn (Builder $q) => $q->where('sede_id', auth()->user()?->sede_id))
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all()),
                Tables\Filters\SelectFilter::make('prioridad')
                    ->label('Prioridad')
                    ->options(Ticket::PRIORIDADES),
                Tables\Filters\TernaryFilter::make('alto_costo')
                    ->label('Alto costo')
                    ->placeholder('Todos')
                    ->trueLabel('Solo alto costo')
                    ->falseLabel('Sin alto costo'),
                Tables\Filters\SelectFilter::make('sede_id')
                    ->label('Sede')
                    ->options(fn (): array => Sede::orderBy('nombre')->pluck('nombre', 'id')->all())
                    ->visible(fn (): bool => (bool) auth()->user()?->es_administrador),
                Tables\Filters\Filter::make('solo_hoy')
                    ->label('Solo los de hoy')
                    ->query(fn (Builder $query): Builder => $query->whereDate('fecha', today()))
                    ->default(),
            ])
            ->actions([
                Tables\Actions\Action::make('alistar')
                    ->label('Alistar')
                    ->icon('heroicon-m-clipboard-document-check')
                    ->color('warning')
                    ->visible(fn (Ticket $record): bool => $record->sePuedeAlistar()
                        && (bool) auth()->user()?->puede('tickets.alistar'))
                    ->modalHeading(fn (Ticket $record): string => "Alistar el ticket {$record->turno}")
                    ->modalDescription('Captura los medicamentos de la orden médica. Al guardar, el ticket queda listo para entrega.')
                    ->modalSubmitActionLabel('Dejar listo')
                    ->fillForm(fn (Ticket $record): array => [
                        'items' => $record->items->map(fn ($item): array => [
                            'codigo' => $item->codigo,
                            'nombre' => $item->nombre,
                            'cantidad' => $item->cantidad,
                            'unidad' => $item->unidad,
                            'observacion' => $item->observacion,
                        ])->all(),
                    ])
                    ->form([
                        Forms\Components\Repeater::make('items')
                            ->label('Medicamentos')
                            ->schema([
                                Forms\Components\TextInput::make('codigo')
                                    ->label('Código')
                                    ->required()
                                    ->maxLength(64),
                                Forms\Components\TextInput::make('nombre')
                                    ->label('Medicamento')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(2),
                                Forms\Components\TextInput::make('cantidad')
                                    ->label('Cantidad')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.01),
                                Forms\Components\TextInput::make('unidad')
                                    ->label('Unidad')
                                    ->default('UND')
                                    ->maxLength(20),
                                Forms\Components\TextInput::make('observacion')
                                    ->label('Observación')
                                    ->maxLength(255)
                                    ->columnSpan(2),
                            ])
                            ->columns(3)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Agregar medicamento')
                            ->reorderable(false),
                    ])
                    ->action(function (Ticket $record, array $data, AlistarTicket $alistar): void {
                        try {
                            $alistar->handle($record, auth()->user(), $data['items'] ?? []);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()
                                ->title('No se pudo alistar')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title("Ticket {$record->turno} listo para entrega")
                            ->body('Ya se puede llamar al paciente.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('anular')
                    ->label('Anular')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->visible(fn (Ticket $record): bool => ! $record->estaCerrado()
                        && (bool) auth()->user()?->puede('tickets.anular'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Ticket $record): string => "Anular el ticket {$record->turno}")
                    ->modalDescription('El ticket deja de atenderse. Esto no se puede deshacer.')
                    ->modalSubmitActionLabel('Anular')
                    ->form([
                        Forms\Components\TextInput::make('motivo')
                            ->label('Motivo')
                            ->placeholder('El paciente se retiró, orden duplicada…')
                            ->required()
                            ->maxLength(160),
                    ])
                    ->action(function (Ticket $record, array $data, AlistarTicket $servicio): void {
                        try {
                            $servicio->anular($record, auth()->user(), $data['motivo']);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title('No se pudo anular')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title("Ticket {$record->turno} anulado")->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('El ticket')
                    ->schema([
                        Infolists\Components\TextEntry::make('turno')
                            ->label('Turno')
                            ->badge()
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->color(fn (Ticket $record): string => $record->esPreferencial() ? 'warning' : 'primary'),
                        Infolists\Components\TextEntry::make('numero')
                            ->label('Número')
                            ->copyable()
                            ->copyMessage('Número copiado'),
                        Infolists\Components\TextEntry::make('estado')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Ticket::ESTADOS[$state] ?? $state)
                            ->color(fn (string $state): string => Ticket::COLORES_ESTADO[$state] ?? 'gray'),
                        Infolists\Components\TextEntry::make('cola.nombre')->label('Cola'),
                        Infolists\Components\TextEntry::make('sede.nombre')->label('Sede'),
                        Infolists\Components\TextEntry::make('fecha')->label('Fecha')->date('d/m/Y'),
                        Infolists\Components\TextEntry::make('prioridad')
                            ->label('Prioridad')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Ticket::PRIORIDADES[$state] ?? $state)
                            ->color(fn (string $state): string => $state === Ticket::PRIORIDAD_PREFERENCIAL ? 'warning' : 'gray'),
                        Infolists\Components\TextEntry::make('motivo_prioridad')
                            ->label('Motivo')
                            ->formatStateUsing(fn (?string $state): string => Ticket::MOTIVOS_PRIORIDAD[$state] ?? '—')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('alto_costo')
                            ->label('Alto costo u oncológico')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Sí' : 'No')
                            ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Paciente')
                    ->schema([
                        Infolists\Components\TextEntry::make('paciente.documento_completo')->label('Documento'),
                        Infolists\Components\TextEntry::make('paciente.nombre_completo')->label('Nombre'),
                        Infolists\Components\TextEntry::make('paciente.estado_afiliacion')
                            ->label('Afiliación')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('paciente.telefono_movil')
                            ->label('Teléfono')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('paciente.direccion')
                            ->label('Dirección')
                            ->placeholder('—')
                            ->columnSpan(2),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Medicamentos')
                    ->description('Los captura farmacia al alistar.')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->schema([
                                Infolists\Components\TextEntry::make('codigo')->label('Código'),
                                Infolists\Components\TextEntry::make('nombre')->label('Medicamento')->columnSpan(2),
                                Infolists\Components\TextEntry::make('cantidad')->label('Cantidad'),
                                Infolists\Components\TextEntry::make('unidad')->label('Unidad'),
                                Infolists\Components\TextEntry::make('observacion')
                                    ->label('Observación')
                                    ->placeholder('—')
                                    ->columnSpan(2),
                            ])
                            ->columns(3),
                    ])
                    ->visible(fn (Ticket $record): bool => $record->items()->exists()),

                Infolists\Components\Section::make('Seguimiento')
                    ->schema([
                        Infolists\Components\TextEntry::make('creadoPor.nombre_completo')
                            ->label('Generado por')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Generado el')
                            ->dateTime('d/m/Y H:i'),
                        Infolists\Components\TextEntry::make('alistadoPor.nombre_completo')
                            ->label('Alistado por')
                            ->placeholder('Sin alistar'),
                        Infolists\Components\TextEntry::make('alistado_en')
                            ->label('Alistado el')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('estado_sala')
                            ->label('En la sala')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Ticket::ESTADOS_SALA[$state] ?? $state)
                            ->color(fn (string $state): string => Ticket::COLORES_SALA[$state] ?? 'gray'),
                        Infolists\Components\TextEntry::make('ventanilla.nombre')
                            ->label('Llamado desde')
                            ->placeholder('Sin llamar'),
                        Infolists\Components\TextEntry::make('llamado_en')
                            ->label('Llamado el')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—')
                            // Cuántas veces se llamó: es lo que discute el paciente en el mostrador.
                            ->helperText(fn (Ticket $record): ?string => $record->llamados()->count() > 1
                                ? $record->llamados()->count().' llamados'
                                : null),
                        Infolists\Components\TextEntry::make('motivo_anulacion')
                            ->label('Motivo de anulación')
                            ->placeholder('—')
                            ->columnSpan(2),
                        Infolists\Components\TextEntry::make('observaciones')
                            ->label('Observaciones')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTickets::route('/'),
            'view' => Pages\ViewTicket::route('/{record}'),
        ];
    }
}
