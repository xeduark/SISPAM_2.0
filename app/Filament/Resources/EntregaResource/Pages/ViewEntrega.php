<?php

namespace App\Filament\Resources\EntregaResource\Pages;

use App\Filament\Resources\EntregaResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewEntrega extends ViewRecord
{
    protected static string $resource = EntregaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('Facturación'),
        ];
    }
}
