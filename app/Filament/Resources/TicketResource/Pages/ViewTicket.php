<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Models\Ticket;
use Filament\Actions\Action;
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
        /** @var Ticket $ticket */
        $ticket = $this->getRecord();

        return [
            // Reimprimir el papel del paciente. El permiso y la sede los
            // vuelve a exigir la ruta: esto solo evita mostrar un botón que
            // llevaría a un 403.
            Action::make('imprimir')
                ->label('Imprimir')
                ->icon('heroicon-m-printer')
                ->color('gray')
                ->visible(fn (): bool => TicketResource::sePuedeImprimir($ticket))
                ->url(route('tickets.imprimir', $ticket), shouldOpenInNewTab: true),
        ];
    }
}
