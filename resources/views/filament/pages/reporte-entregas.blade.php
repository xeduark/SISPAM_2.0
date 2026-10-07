<x-filament-panels::page>
    <form wire:submit="consultar" class="space-y-4">
        <x-filament::section>
            <x-slot name="heading">Filtros</x-slot>
            {{ $this->form }}
            <div class="mt-4 flex flex-wrap gap-3">
                <x-filament::button type="submit" icon="heroicon-m-magnifying-glass">
                    Consultar
                </x-filament::button>
                <x-filament::button color="gray" outlined type="button" wire:click="exportarCsv" icon="heroicon-m-arrow-down-tray">
                    Exportar CSV
                </x-filament::button>
            </div>
        </x-filament::section>
    </form>

    @if ($resultados !== null)
        <x-filament::section>
            <x-slot name="heading">Resultados ({{ $resultados->count() }})</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-gray-500">
                            <th class="py-2 pr-3">Ticket</th>
                            <th class="py-2 pr-3">Paciente / Doc.</th>
                            <th class="py-2 pr-3">Tipo</th>
                            <th class="py-2 pr-3">Estado</th>
                            <th class="py-2 pr-3">Sede de atención</th>
                            <th class="py-2 pr-3">Usuario</th>
                            <th class="py-2 pr-3">Fecha</th>
                            <th class="py-2">Ítems (pend.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($resultados as $entrega)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-2 pr-3">{{ $entrega->ticket_numero }}</td>
                                <td class="py-2 pr-3">
                                    {{ $entrega->paciente?->nombre_completo ?? '—' }}
                                    <div class="text-xs text-gray-500">
                                        {{ $entrega->paciente?->tipo_documento }} {{ $entrega->paciente?->numero_documento }}
                                    </div>
                                </td>
                                <td class="py-2 pr-3">{{ $entrega->tipo }}</td>
                                <td class="py-2 pr-3">
                                    {{ $entrega->estado }}
                                    @if ($entrega->domicilioEnvio)
                                        <div class="text-xs text-gray-500">Dom: {{ $entrega->domicilioEnvio->estado }}</div>
                                    @endif
                                </td>
                                <td class="py-2 pr-3">{{ $entrega->sede?->nombre }}</td>
                                <td class="py-2 pr-3">{{ $entrega->usuario?->nombre_completo }}</td>
                                <td class="py-2 pr-3">{{ $entrega->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="py-2">
                                    {{ $entrega->items->count() }}
                                    (pend. {{ $entrega->items->sum('cantidad_pendiente') }})
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-4 text-gray-500">No hay entregas con esos filtros.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
