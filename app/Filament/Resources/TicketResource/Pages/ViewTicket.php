<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public function getTitle(): string
    {
        return "Ticket {$this->getRecord()->turno}";
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
