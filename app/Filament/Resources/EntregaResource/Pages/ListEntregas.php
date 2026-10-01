<?php

namespace App\Filament\Resources\EntregaResource\Pages;

use App\Filament\Resources\EntregaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEntregas extends ListRecords
{
    protected static string $resource = EntregaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('atender')
                ->label('Atender entrega')
                ->icon('heroicon-o-clipboard-document-check')
                ->url(fn (): string => \App\Filament\Pages\AtenderEntrega::getUrl())
                ->visible(fn (): bool => (bool) auth()->user()?->puede('entrega.atender')),
        ];
    }
}
