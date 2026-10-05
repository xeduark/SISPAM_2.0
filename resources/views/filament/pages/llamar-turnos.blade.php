{{--
    Pantalla de la ventanilla. Se refresca sola cada 15 segundos para que la
    espera esté al día sin que nadie tenga que recargar.
--}}
<x-filament-panels::page>
    @php
        $ventanilla = $this->ventanilla();
        $enAtencion = $this->enAtencion();
        $enEspera = $this->enEspera();
        $ausentes = $this->ausentes();
        $urlSala = $this->urlDeLaSala();
        $puedeLlamar = (bool) auth()->user()?->puede('turnos.llamar');
        $puedeAusentar = (bool) auth()->user()?->puede('turnos.ausente');
    @endphp

    <x-filament::section>
        <x-slot name="heading">Desde dónde llamas</x-slot>
        <x-slot name="description">
            Los preferenciales se llaman de primeras; dentro de cada grupo, el que llegó primero.
        </x-slot>

        {{ $this->configForm }}

        @if ($urlSala)
            <div class="mt-4">
                <x-filament::link :href="$urlSala" target="_blank" icon="heroicon-m-tv">
                    Abrir la pantalla de la sala
                </x-filament::link>
            </div>
        @endif
    </x-filament::section>

    @if (! $ventanilla)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <x-slot name="heading">Sin ventanilla</x-slot>
            Tu sede no tiene ventanillas activas. Se crean en Administración → Ventanillas.
        </x-filament::section>
    @else
        <div wire:poll.15s>
            {{-- El turno que esta ventanilla tiene en la mano --}}
            <x-filament::section
                :icon="$enAtencion ? 'heroicon-o-megaphone' : 'heroicon-o-clock'"
                :icon-color="$enAtencion ? 'primary' : 'gray'"
            >
                <x-slot name="heading">{{ $ventanilla->nombre }}</x-slot>
                <x-slot name="description">
                    @if ($enAtencion)
                        Llamado {{ $enAtencion->llamado_en?->diffForHumans() }}
                    @else
                        Sin turno en la mano.
                    @endif
                </x-slot>

                @if ($enAtencion)
                    <div class="flex flex-wrap items-center gap-6">
                        <div>
                            <div class="text-5xl font-bold tracking-tight text-primary-600 dark:text-primary-400">
                                {{ $enAtencion->turno }}
                            </div>
                            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ $enAtencion->numero }}
                            </div>
                        </div>

                        <dl class="grid flex-1 gap-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">Paciente</dt>
                                <dd>
                                    {{ $enAtencion->paciente?->documento_completo }}
                                    — {{ $enAtencion->paciente?->nombre_completo }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">Cola</dt>
                                <dd>{{ $enAtencion->cola?->nombre }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">Prioridad</dt>
                                <dd>
                                    <x-filament::badge :color="$enAtencion->esPreferencial() ? 'warning' : 'gray'">
                                        {{ \App\Models\Ticket::PRIORIDADES[$enAtencion->prioridad] ?? $enAtencion->prioridad }}
                                    </x-filament::badge>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">Intentos</dt>
                                <dd>{{ $enAtencion->llamados()->count() }}</dd>
                            </div>
                        </dl>
                    </div>
                @endif

                <div class="mt-4 flex flex-wrap gap-3">
                    <x-filament::button
                        wire:click="llamarSiguiente"
                        icon="heroicon-m-megaphone"
                        size="lg"
                        :disabled="! $puedeLlamar"
                    >
                        Llamar siguiente
                    </x-filament::button>

                    @if ($enAtencion)
                        <x-filament::button
                            color="gray"
                            outlined
                            icon="heroicon-m-arrow-path"
                            wire:click="llamarEste({{ $enAtencion->getKey() }})"
                            :disabled="! $puedeLlamar"
                        >
                            Volver a llamar
                        </x-filament::button>

                        <x-filament::button
                            color="danger"
                            outlined
                            icon="heroicon-m-user-minus"
                            wire:click="noSePresento({{ $enAtencion->getKey() }})"
                            :disabled="! $puedeAusentar"
                        >
                            No se presentó
                        </x-filament::button>
                    @endif
                </div>
            </x-filament::section>

            {{-- La espera, en el orden en que se va a llamar --}}
            <x-filament::section class="mt-6" collapsible>
                <x-slot name="heading">En espera ({{ $enEspera->count() }})</x-slot>
                <x-slot name="description">En este orden se llaman. Se puede llamar a uno puntual si hace falta.</x-slot>

                @if ($enEspera->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Nadie esperando. Cuando farmacia deje un ticket listo, aparece aquí.
                    </p>
                @else
                    <ul class="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        @foreach ($enEspera as $ticket)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                                <div class="flex items-center gap-3">
                                    <x-filament::badge :color="$ticket->esPreferencial() ? 'warning' : 'primary'">
                                        {{ $ticket->turno }}
                                    </x-filament::badge>
                                    <span class="text-gray-500 dark:text-gray-400">
                                        {{ $ticket->cola?->nombre }}
                                        @if ($ticket->esPreferencial())
                                            · {{ \App\Models\Ticket::MOTIVOS_PRIORIDAD[$ticket->motivo_prioridad] ?? 'Preferencial' }}
                                        @endif
                                        @if ($ticket->alto_costo)
                                            · Alto costo
                                        @endif
                                        · espera {{ $ticket->created_at?->diffForHumans(null, true) }}
                                    </span>
                                </div>

                                <x-filament::button
                                    size="xs"
                                    color="gray"
                                    outlined
                                    icon="heroicon-m-megaphone"
                                    wire:click="llamarEste({{ $ticket->getKey() }})"
                                    :disabled="! $puedeLlamar"
                                >
                                    Llamar este
                                </x-filament::button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>

            {{-- Los que no aparecieron: no vuelven solos a la espera --}}
            @if ($ausentes->isNotEmpty())
                <x-filament::section class="mt-6" icon="heroicon-o-user-minus" icon-color="danger" collapsible collapsed>
                    <x-slot name="heading">No se presentaron ({{ $ausentes->count() }})</x-slot>
                    <x-slot name="description">Siguen con su turno: si aparecen, se vuelven a llamar.</x-slot>

                    <ul class="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        @foreach ($ausentes as $ticket)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                                <div class="flex items-center gap-3">
                                    <x-filament::badge color="danger">{{ $ticket->turno }}</x-filament::badge>
                                    <span class="text-gray-500 dark:text-gray-400">
                                        {{ $ticket->cola?->nombre }}
                                        · llamado {{ $ticket->llamado_en?->diffForHumans() }}
                                        desde {{ $ticket->ventanilla?->nombre ?? '—' }}
                                    </span>
                                </div>

                                <x-filament::button
                                    size="xs"
                                    color="gray"
                                    outlined
                                    icon="heroicon-m-arrow-path"
                                    wire:click="llamarEste({{ $ticket->getKey() }})"
                                    :disabled="! $puedeLlamar"
                                >
                                    Volver a llamar
                                </x-filament::button>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endif

            {{-- Lo mismo que está viendo el paciente en la sala --}}
            @php $ultimos = $this->ultimosLlamados(); @endphp
            @if ($ultimos->isNotEmpty())
                <x-filament::section class="mt-6" icon="heroicon-o-tv" collapsible collapsed>
                    <x-slot name="heading">Últimos llamados de la sede</x-slot>
                    <x-slot name="description">Es lo que se ve en la pantalla de la sala.</x-slot>

                    <ul class="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        @foreach ($ultimos as $llamado)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                                <span class="font-semibold">{{ $llamado->ticket?->turno }}</span>
                                <span class="text-gray-500 dark:text-gray-400">
                                    {{ $llamado->ventanilla?->nombre ?? '—' }}
                                    · intento {{ $llamado->intento }}
                                    · {{ $llamado->created_at?->format('H:i') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
