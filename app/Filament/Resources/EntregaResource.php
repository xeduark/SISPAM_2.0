<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EntregaResource\Pages;
use App\Models\Entrega;
use App\Models\EntregaItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EntregaResource extends Resource
{
    protected static string $modulo = 'entrega';

    protected static ?string $model = Entrega::class;

    protected static ?string $modelLabel = 'Entrega';

    protected static ?string $pluralModelLabel = 'Entregas';

    protected static ?string $navigationLabel = 'Entregas';

    protected static ?string $navigationGroup = 'Entrega';

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 2;

    public static function can(string $action, ?Model $record = null): bool
    {
        $accion = match ($action) {
            'viewAny', 'view' => 'ver',
            'update' => 'atender',
            default => null,
        };

        if ($accion === null) {
            return false;
        }

        return (bool) auth()->user()?->puede(static::$modulo.'.'.$accion);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('factura_referencia')
                ->label('Referencia de factura')
                ->maxLength(80),
            Forms\Components\Select::make('facturacion_estado')
                ->label('Estado de facturación')
                ->options([
                    Entrega::FACTURACION_NO_APLICA => 'No aplica',
                    Entrega::FACTURACION_PENDIENTE => 'Pendiente',
                    Entrega::FACTURACION_MARCADA => 'Marcada',
                ])
                ->required(),
            Forms\Components\Textarea::make('observaciones')
                ->label('Observaciones')
                ->columnSpanFull(),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Entrega')->schema([
                Infolists\Components\TextEntry::make('ticket_numero')->label('Ticket'),
                Infolists\Components\TextEntry::make('tipo')->badge(),
                Infolists\Components\TextEntry::make('estado')->badge(),
                Infolists\Components\TextEntry::make('sede.nombre')->label('Sede'),
                Infolists\Components\TextEntry::make('usuario.nombre_completo')->label('Atendido por'),
                Infolists\Components\TextEntry::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
            ])->columns(3),
            Infolists\Components\Section::make('Paciente y receptor')->schema([
                Infolists\Components\TextEntry::make('paciente.nombre_completo')->label('Paciente')->placeholder('—'),
                Infolists\Components\TextEntry::make('receptor_nombre')->label('Receptor'),
                Infolists\Components\TextEntry::make('receptor_documento')->label('Doc. receptor'),
                Infolists\Components\TextEntry::make('receptor_parentesco')->label('Parentesco'),
                Infolists\Components\TextEntry::make('observaciones')->columnSpanFull(),
            ])->columns(2),
            Infolists\Components\Section::make('Facturación')->schema([
                Infolists\Components\TextEntry::make('facturacion_estado')->label('Estado')->badge(),
                Infolists\Components\TextEntry::make('factura_referencia')->label('Referencia')->placeholder('—'),
            ])->columns(2),
            Infolists\Components\Section::make('Medicamentos')->schema([
                Infolists\Components\RepeatableEntry::make('items')->schema([
                    Infolists\Components\TextEntry::make('codigo'),
                    Infolists\Components\TextEntry::make('nombre'),
                    Infolists\Components\TextEntry::make('cantidad_solicitada')->label('Solicitada'),
                    Infolists\Components\TextEntry::make('cantidad_entregada')->label('Entregada'),
                    Infolists\Components\TextEntry::make('resultado')->badge(),
                    Infolists\Components\TextEntry::make('motivo')->placeholder('—'),
                ])->columns(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ticket_numero')->label('Ticket')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tipo')->badge()->sortable(),
                Tables\Columns\TextColumn::make('estado')->badge()->sortable(),
                Tables\Columns\TextColumn::make('paciente.nombre_completo')->label('Paciente')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('sede.nombre')->label('Sede')->toggleable(),
                Tables\Columns\TextColumn::make('facturacion_estado')->label('Facturación')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('tipo')->options([
                    Entrega::TIPO_PRESENCIAL => 'Presencial',
                    Entrega::TIPO_DOMICILIO => 'Domicilio',
                ]),
                Tables\Filters\SelectFilter::make('estado')->options([
                    Entrega::ESTADO_EN_PROCESO => 'En proceso',
                    Entrega::ESTADO_PARCIAL => 'Parcial',
                    Entrega::ESTADO_COMPLETADA => 'Completada',
                    Entrega::ESTADO_ANULADA => 'Anulada',
                ]),
                Tables\Filters\Filter::make('faltantes_pendientes')
                    ->label('Con faltantes o pendientes')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'items',
                        fn (Builder $q) => $q->whereIn('resultado', [
                            EntregaItem::RESULTADO_FALTANTE,
                            EntregaItem::RESULTADO_PENDIENTE,
                        ])
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->label('Facturación'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEntregas::route('/'),
            'view' => Pages\ViewEntrega::route('/{record}'),
            'edit' => Pages\EditEntrega::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
