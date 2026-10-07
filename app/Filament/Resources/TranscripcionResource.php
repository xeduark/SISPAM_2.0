<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ControlaPermisos;
use App\Filament\Concerns\FiltraPorSede;
use App\Filament\Resources\TranscripcionResource\Pages;
use App\Jobs\LeerFormula;
use App\Models\Sede;
use App\Models\Transcripcion;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Las fórmulas leídas (Gemini o Google Vision), esperando que una transcriptora las
 * compare con el original y las confirme. Nacen solas al cargar la orden
 * médica: aquí no se crean ni se borran.
 */
class TranscripcionResource extends Resource
{
    use ControlaPermisos, FiltraPorSede;

    protected static string $modulo = 'transcripcion';

    protected static ?string $model = Transcripcion::class;

    protected static ?string $slug = 'transcripciones';

    protected static ?string $modelLabel = 'Transcripción';

    protected static ?string $pluralModelLabel = 'Fórmulas por transcribir';

    protected static ?string $navigationGroup = 'Transcripción';

    protected static ?string $navigationLabel = 'Fórmulas por transcribir';

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?int $navigationSort = 1;

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

    /** Cuántas fórmulas ya leídas esperan revisión en la sede. */
    public static function getNavigationBadge(): ?string
    {
        $pendientes = static::getEloquentQuery()->where('estado', Transcripcion::ESTADO_LEIDA)->count();

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
                Tables\Columns\TextColumn::make('paciente.documento_completo')
                    ->label('Paciente')
                    ->description(fn (Transcripcion $record): ?string => $record->paciente?->nombre_completo)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('paciente', fn (Builder $q) => $q
                            ->where('numero_documento', 'like', "%{$search}%")
                            ->orWhere('primer_apellido', 'like', "%{$search}%")
                            ->orWhere('primer_nombre', 'like', "%{$search}%"))),
                Tables\Columns\TextColumn::make('ticket.turno')
                    ->label('Turno')
                    ->badge()
                    ->description(fn (Transcripcion $record): ?string => $record->ticket?->numero)
                    ->placeholder('Sin ticket'),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Transcripcion::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state): string => Transcripcion::COLORES_ESTADO[$state] ?? 'gray')
                    ->description(fn (Transcripcion $record): ?string => $record->estado === Transcripcion::ESTADO_FALLIDA ? $record->error : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('verificacion_cedula')
                    ->label('Cédula')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Transcripcion::VERIFICACIONES_CEDULA[$state] ?? '—')
                    ->color(fn (?string $state): string => $state === Transcripcion::CEDULA_COINCIDE ? 'success' : 'warning')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Medicamentos')
                    ->counts('items')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Cargada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sede.nombre')
                    ->label('Sede')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Por orden de llegada: la que más lleva esperando, primero.
            ->recordUrl(fn (Transcripcion $record): string => static::getUrl('revisar', ['record' => $record]))
            ->defaultSort('created_at')
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(Transcripcion::ESTADOS)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('sede_id')
                    ->label('Sede')
                    ->options(fn (): array => Sede::orderBy('nombre')->pluck('nombre', 'id')->all())
                    ->visible(fn (): bool => (bool) auth()->user()?->es_administrador),
            ])
            ->actions([
                Tables\Actions\Action::make('revisar')
                    ->label('Revisar')
                    ->icon('heroicon-m-document-magnifying-glass')
                    ->url(fn (Transcripcion $record): string => static::getUrl('revisar', ['record' => $record])),
                Tables\Actions\Action::make('reintentar')
                    ->label('Leer de nuevo')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->visible(fn (Transcripcion $record): bool => $record->estado === Transcripcion::ESTADO_FALLIDA
                        && (bool) auth()->user()?->puede('transcripcion.transcribir'))
                    ->requiresConfirmation()
                    ->modalDescription('Se vuelve a enviar la fórmula al motor de lectura.')
                    ->action(function (Transcripcion $record): void {
                        $record->update(['estado' => Transcripcion::ESTADO_EN_COLA, 'error' => null]);
                        LeerFormula::dispatch($record);

                        Notification::make()->title('La fórmula volvió a la cola')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTranscripciones::route('/'),
            'revisar' => Pages\RevisarTranscripcion::route('/{record}'),
        ];
    }
}
