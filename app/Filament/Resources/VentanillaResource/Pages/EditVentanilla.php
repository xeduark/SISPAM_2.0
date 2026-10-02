<?php

namespace App\Filament\Resources\VentanillaResource\Pages;

use App\Filament\Resources\VentanillaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVentanilla extends EditRecord
{
    protected static string $resource = VentanillaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
