<?php

namespace App\Filament\Resources\ColaResource\Pages;

use App\Filament\Resources\ColaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListColas extends ListRecords
{
    protected static string $resource = ColaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nueva cola'),
        ];
    }
}
