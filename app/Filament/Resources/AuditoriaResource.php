<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ControlaPermisos;
use App\Filament\Resources\AuditoriaResource\Pages;
use App\Models\Auditoria;
use App\Models\Sede;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rastro de quién hizo qué. Solo se consulta: ni el administrador puede
 * crear, editar ni borrar una línea de auditoría.
 */
class AuditoriaResource extends Resource
{
    use ControlaPermisos;

    protected static string $modulo = 'auditoria';

    protected static ?string $model = Auditoria::class;

    protected static ?string $modelLabel = 'Registro de auditoría';

    protected static ?string $pluralModelLabel = 'Auditoría';

    protected static ?string $navigationLabel = 'Auditoría';

    protected static ?string $navigationGroup = 'Administración';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 9;

    /* ------------------------------------------------------------------ *
     *  Una auditoría no se escribe desde la pantalla, pase lo que pase.
     * ------------------------------------------------------------------ */

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha y hora')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('usuario_nombre')
                    ->label('Usuario')
                    ->description(fn (Auditoria $record): ?string => $record->usuario_documento)
                    ->searchable(['usuario_nombre', 'usuario_documento'])
                    ->sortable(),
                Tables\Columns\TextColumn::make('sede.nombre')
                    ->label('Sede')
                    ->formatStateUsing(fn ($record): string => $record->sede?->etiqueta ?? '—')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('accion')
                    ->label('Acción')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Auditoria::ACCIONES[$state] ?? $state)
                    ->color(fn (string $state): string => Auditoria::COLORES[$state] ?? 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('descripcion')
                    ->label('Detalle')
                    ->searchable()
                    ->wrap()
                    ->limit(90),
                Tables\Columns\TextColumn::make('ip')
                    ->label('IP')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('accion')
                    ->label('Acción')
                    ->options(Auditoria::ACCIONES)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('sede_id')
                    ->label('Sede')
                    ->options(fn (): array => Sede::opciones()),
                Tables\Filters\SelectFilter::make('entidad_tipo')
                    ->label('Sobre qué')
                    ->options(fn (): array => Auditoria::query()
                        ->whereNotNull('entidad_tipo')
                        ->distinct()
                        ->orderBy('entidad_tipo')
                        ->pluck('entidad_tipo', 'entidad_tipo')
                        ->all()),
                Tables\Filters\Filter::make('fecha')
                    ->form([
                        DatePicker::make('desde')
                            ->label('Desde')
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('hasta')
                            ->label('Hasta')
                            ->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['desde'] ?? null, fn (Builder $q, $f) => $q->whereDate('created_at', '>=', $f))
                        ->when($data['hasta'] ?? null, fn (Builder $q, $f) => $q->whereDate('created_at', '<=', $f))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->paginationPageOptions([25, 50, 100]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Qué pasó')
                    ->schema([
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Fecha y hora')
                            ->dateTime('d/m/Y H:i:s'),
                        Infolists\Components\TextEntry::make('accion')
                            ->label('Acción')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Auditoria::ACCIONES[$state] ?? $state)
                            ->color(fn (string $state): string => Auditoria::COLORES[$state] ?? 'gray'),
                        Infolists\Components\TextEntry::make('entidad_tipo')
                            ->label('Sobre qué')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('descripcion')
                            ->label('Detalle')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Quién')
                    ->schema([
                        Infolists\Components\TextEntry::make('usuario_nombre')
                            ->label('Usuario'),
                        Infolists\Components\TextEntry::make('usuario_documento')
                            ->label('Documento')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('sede.nombre')
                            ->label('Sede')
                            ->formatStateUsing(fn ($record): string => $record->sede?->etiqueta ?? '—')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('ip')
                            ->label('Dirección IP')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('navegador')
                            ->label('Navegador')
                            ->placeholder('—')
                            ->columnSpan(2),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Qué cambió')
                    ->description('Solo los campos que el modelo declara auditables. Los datos de salud nunca se registran.')
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('cambios')
                            ->hiddenLabel()
                            ->keyLabel('Campo')
                            ->valueLabel('Antes → Ahora')
                            ->state(fn (Auditoria $record): array => collect($record->cambios ?? [])
                                ->map(fn (array $par): string => ($par[0] ?? '—').' → '.($par[1] ?? '—'))
                                ->all()),
                    ])
                    ->visible(fn (Auditoria $record): bool => filled($record->cambios)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditorias::route('/'),
            'view' => Pages\ViewAuditoria::route('/{record}'),
        ];
    }
}
