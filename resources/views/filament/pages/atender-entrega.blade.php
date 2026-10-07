<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Flujo de atención</x-slot>
        <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
            <li>Consulta el número de turno.</li>
            <li>Elige la sede de atención y revisa los datos del paciente.</li>
            <li>Indica las cantidades a entregar por medicamento.</li>
            <li>En presencial: registra receptor y firma. En domicilio: se prepara el envío.</li>
            <li>Revisa el resumen y confirma la entrega.</li>
        </ol>
    </x-filament::section>

    <form wire:submit="buscar">
        <x-filament::section>
            <x-slot name="heading">1. Consultar turno</x-slot>
            <x-slot name="description">
                Consulta el número de turno para visualizar los medicamentos pendientes de dispensación.
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
            <x-slot name="description">2. Datos del paciente</x-slot>

            <dl class="mb-2 grid gap-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Paciente</dt>
                    <dd class="font-medium">{{ $ticket['paciente']['nombre_completo'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Documento</dt>
                    <dd>{{ $ticket['paciente']['tipo_documento'] }} {{ $ticket['paciente']['numero_documento'] }}</dd>
                </div>
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
        </x-filament::section>

        @if ($ticketBloqueado)
            <x-filament::section icon="heroicon-o-lock-closed" icon-color="warning">
                <x-slot name="heading">Dispensación bloqueada</x-slot>
                {{ $mensajeBloqueo ?? 'Este ticket ya fue dispensado completamente.' }}
            </x-filament::section>
        @else
            <form wire:submit="registrar" class="space-y-4">
                {{ $this->atencionForm }}

                <div class="flex justify-end gap-3">
                    <x-filament::button
                        type="submit"
                        icon="heroicon-m-check"
                        color="primary"
                        wire:loading.attr="disabled"
                        wire:target="registrar"
                        :disabled="$procesando"
                    >
                        <span wire:loading.remove wire:target="registrar">Confirmar y registrar entrega</span>
                        <span wire:loading wire:target="registrar">Registrando…</span>
                    </x-filament::button>
                </div>
            </form>
        @endif
    @elseif ($consultado)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <x-slot name="heading">Sin ticket</x-slot>
            No se encontró un ticket disponible con ese número de turno.
        </x-filament::section>
    @endif
</x-filament-panels::page>
