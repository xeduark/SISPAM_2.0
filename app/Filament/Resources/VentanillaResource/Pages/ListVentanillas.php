<?php

namespace App\Filament\Resources\VentanillaResource\Pages;

use App\Filament\Resources\VentanillaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVentanillas extends ListRecords
{
    protected static string $resource = VentanillaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nueva ventanilla'),
        ];
    }
}
