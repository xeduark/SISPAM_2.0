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
            // El acta que firma el paciente: lo entregado con lote, pendientes y lo que no se dispensa.
            Actions\Action::make('acta')
                ->label('Acta de entrega')
                ->icon('heroicon-m-printer')
                ->color('gray')
                ->url(fn (): string => route('entregas.acta', $this->getRecord()), shouldOpenInNewTab: true),
            Actions\EditAction::make()->label('Facturación'),
        ];
    }
}
