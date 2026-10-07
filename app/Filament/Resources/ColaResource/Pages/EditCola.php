<?php

namespace App\Filament\Resources\ColaResource\Pages;

use App\Filament\Resources\ColaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCola extends EditRecord
{
    protected static string $resource = ColaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(fn (Actions\DeleteAction $action) => ColaResource::cancelarSiYaEntregoTurnos(
                    $action,
                    $this->getRecord(),
                )),
        ];
    }
}
