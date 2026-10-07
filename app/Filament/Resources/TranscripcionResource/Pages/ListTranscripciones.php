<?php

namespace App\Filament\Resources\TranscripcionResource\Pages;

use App\Filament\Resources\TranscripcionResource;
use App\Models\Transcripcion;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTranscripciones extends ListRecords
{
    protected static string $resource = TranscripcionResource::class;

    /** Nacen al cargar la orden médica: aquí no se crean. */
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        $pendientes = [Transcripcion::ESTADO_EN_COLA, Transcripcion::ESTADO_LEIDA, Transcripcion::ESTADO_EN_REVISION];

        return [
            'por_revisar' => Tab::make('Por revisar')
                ->badge(fn (): int => TranscripcionResource::getEloquentQuery()->whereIn('estado', $pendientes)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('estado', $pendientes)),

            'fallidas' => Tab::make('Falló la lectura')
                ->badge(fn (): ?int => TranscripcionResource::getEloquentQuery()->where('estado', Transcripcion::ESTADO_FALLIDA)->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('estado', Transcripcion::ESTADO_FALLIDA)),

            'todas' => Tab::make('Todas'),
        ];
    }
}
