<?php

namespace App\Filament\Resources\TicketResource\Pages;

use App\Filament\Resources\TicketResource;
use App\Models\Ticket;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    /** Los tickets nacen con el paciente: aquí no se crean. */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Pestañas por donde va el ticket, para que farmacia vea de una lo suyo.
     */
    public function getTabs(): array
    {
        return [
            'por_alistar' => Tab::make('Por alistar')
                ->badge(fn (): int => $this->contar([Ticket::ESTADO_GENERADO, Ticket::ESTADO_EN_ALISTAMIENTO]))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('estado', [Ticket::ESTADO_GENERADO, Ticket::ESTADO_EN_ALISTAMIENTO])),

            'listos' => Tab::make('Listos')
                ->badge(fn (): int => $this->contar(Ticket::ESTADOS_ENTREGABLES))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('estado', Ticket::ESTADOS_ENTREGABLES)),

            'todos' => Tab::make('Todos'),
        ];
    }

    /**
     * @param  list<string>  $estados
     */
    private function contar(array $estados): int
    {
        return TicketResource::getEloquentQuery()
            ->whereIn('estado', $estados)
            ->whereDate('fecha', today())
            ->count();
    }
}
