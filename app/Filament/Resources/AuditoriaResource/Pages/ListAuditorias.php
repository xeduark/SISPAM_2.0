<?php

namespace App\Filament\Resources\AuditoriaResource\Pages;

use App\Filament\Resources\AuditoriaResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditorias extends ListRecords
{
    protected static string $resource = AuditoriaResource::class;

    /** Sin botón de crear: la auditoría la escribe el sistema. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
