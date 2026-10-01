<x-filament-panels::page>
    <form wire:submit="buscar">
        <x-filament::section>
            <x-slot name="heading">Buscar ticket</x-slot>
            <x-slot name="description">
                Escribe el número o turno del ticket. Los medicamentos vienen del módulo de ticket (mock mientras no esté listo).
            </x-slot>

            {{ $this->busquedaForm }}

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-m-magnifying-glass">
                    Consultar ticket
                </x-filament::button>

                @if ($consultado)
                    <x-filament::button color="gray" outlined icon="heroicon-m-x-mark" wire:click="limpiar" type="button">
                        Limpiar
                    </x-filament::button>
                @endif
            </div>
        </x-filament::section>
    </form>

    @if ($ticket)
        <x-filament::section>
            <x-slot name="heading">
                Ticket {{ $ticket['numero'] }}
                @if (! empty($ticket['turno']))
                    <span class="text-sm font-normal text-gray-500">(turno {{ $ticket['turno'] }})</span>
                @endif
            </x-slot>
            <x-slot name="description">
                {{ $ticket['paciente']['tipo_documento'] }} {{ $ticket['paciente']['numero_documento'] }}
                — {{ $ticket['paciente']['nombre_completo'] }}
                @if (! empty($ticket['alto_costo']))
                    · Alto costo
                @endif
            </x-slot>

            <dl class="mb-4 grid gap-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Teléfono</dt>
                    <dd>{{ $ticket['paciente']['telefono_movil'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Dirección</dt>
                    <dd>
                        {{ $ticket['paciente']['direccion'] ?: '—' }}
                        @if (! empty($ticket['paciente']['barrio']))
                            · {{ $ticket['paciente']['barrio'] }}
                        @endif
                        @if (! empty($ticket['paciente']['ciudad']))
                            · {{ $ticket['paciente']['ciudad'] }}
                        @endif
                    </dd>
                </div>
                @if (! empty($ticket['paciente']['indicaciones_entrega']))
                    <div class="sm:col-span-2">
                        <dt class="text-gray-500 dark:text-gray-400">Indicaciones de entrega</dt>
                        <dd>{{ $ticket['paciente']['indicaciones_entrega'] }}</dd>
                    </div>
                @endif
            </dl>

            <form wire:submit="registrar" class="space-y-4">
                {{ $this->atencionForm }}

                <div class="flex justify-end gap-3">
                    <x-filament::button type="submit" icon="heroicon-m-check">
                        Registrar entrega
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>
    @elseif ($consultado)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <x-slot name="heading">Sin ticket</x-slot>
            No se encontró un ticket listo para entrega con ese número.
        </x-filament::section>
    @endif
</x-filament-panels::page>
