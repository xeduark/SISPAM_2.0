{{--
    Orientación: la pantalla donde se recibe al paciente.

    Pensada para el celular: una sola columna, campos grandes y el botón de
    generar al alcance del pulgar. En pantallas anchas las secciones se abren
    a dos columnas solas, con el grid que ya trae Filament.

    Tres momentos, excluyentes entre sí:
      ① vacío      — solo el buscador
      ② consultado — la ficha de Savia, el contacto, la fórmula y la prioridad
      ③ generado   — el turno en grande para que el paciente lo lea
--}}
<x-filament-panels::page>

    <div class="sispam-orientacion">

        {{-- ③ El turno recién generado. Va de primero porque es lo que hay
             que mirar cuando aparece. --}}
        @if ($this->ticket)
            <x-filament::section class="sispam-turno">
                <div class="text-center">
                    <p class="text-sm font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">
                        {{ $this->ticket->sede?->etiqueta }}
                    </p>

                    <p class="sispam-turno__numero">{{ $this->ticket->turno }}</p>

                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $this->ticket->numero }}</p>

                    <p class="mt-3 text-lg font-bold">{{ $this->ticket->paciente?->nombre_completo }}</p>

                    <div class="mt-3 flex justify-center">
                        <x-filament::badge :color="$this->ticket->prioridad === \App\Models\Ticket::PRIORIDAD_PREFERENCIAL ? 'warning' : 'gray'" size="lg">
                            {{ \App\Models\Ticket::PRIORIDADES[$this->ticket->prioridad] ?? $this->ticket->prioridad }}
                        </x-filament::badge>
                    </div>

                    <div class="sispam-turno__acciones">
                        @if ($this->puedeImprimir())
                            <x-filament::button
                                tag="a"
                                :href="route('tickets.imprimir', $this->ticket)"
                                target="_blank"
                                icon="heroicon-m-printer"
                                size="lg"
                            >
                                Imprimir ticket
                            </x-filament::button>
                        @endif

                        <x-filament::button
                            color="gray"
                            icon="heroicon-m-arrow-path"
                            size="lg"
                            wire:click="atenderOtro"
                            type="button"
                        >
                            Atender el que sigue
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @else

            {{-- ① El buscador. Enter consulta. --}}
            <form wire:submit="buscar">
                <x-filament::section>
                    <x-slot name="heading">Documento del paciente</x-slot>

                    {{ $this->formularioBusqueda }}

                    <div class="sispam-consulta-savia flex flex-wrap items-center justify-center gap-3">
                        <x-filament::button
                            type="submit"
                            size="lg"
                            icon="heroicon-m-magnifying-glass"
                            class="sispam-boton-consulta"
                            wire:loading.attr="disabled"
                        >
                            <span wire:loading.remove wire:target="buscar">Consultar</span>
                            <span wire:loading wire:target="buscar">Consultando…</span>
                        </x-filament::button>

                        @if ($consultado)
                            <x-filament::button color="gray" outlined icon="heroicon-m-x-mark" wire:click="limpiar" type="button">
                                Limpiar
                            </x-filament::button>
                        @endif
                    </div>
                </x-filament::section>
            </form>

            <div wire:loading wire:target="buscar" class="text-sm text-gray-500 dark:text-gray-400">
                Consultando el servicio de Savia Salud EPS…
            </div>

            {{-- ② El afiliado ya está cargado --}}
            @if ($atributos)
                <div wire:loading.remove wire:target="buscar">

                    {{-- Quién es: lo que el orientador necesita confirmar de un vistazo --}}
                    <x-filament::section class="sispam-ficha">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-lg font-bold">{{ $this->nombreCompleto }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ $this->documento }}
                                    @if ($this->edad !== null)
                                        · {{ $this->edad }} años
                                    @endif
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::badge :color="$this->colorEstado">
                                    {{ $this->estadoAfiliacion ?: 'Sin estado' }}
                                </x-filament::badge>

                                @if ($this->regimen)
                                    <x-filament::badge color="gray">{{ $this->regimen }}</x-filament::badge>
                                @endif
                            </div>
                        </div>

                        @if ($pacienteExistenteId)
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                                Ya está registrado en SISPAM desde el {{ $registradoDesde }}. Al generar el ticket se actualiza, no se duplica.
                            </p>
                        @endif

                        @if (! $this->estaActivo)
                            <p class="mt-3 text-sm font-semibold text-danger-600 dark:text-danger-400">
                                Savia no lo reporta activo. Verifícalo antes de dispensar.
                            </p>
                        @endif
                    </x-filament::section>

                    {{-- Una fórmula que quedó sin turno porque la sede no tenía colas --}}
                    @if ($hojasSinTurno > 0)
                        <x-filament::section
                            icon="heroicon-o-clock"
                            icon-color="danger"
                            class="sispam-visita-abierta"
                        >
                            <x-slot name="heading">Tiene una fórmula sin turno</x-slot>

                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                Quedaron {{ $hojasSinTurno }}
                                {{ $hojasSinTurno === 1 ? 'hoja guardada' : 'hojas guardadas' }}
                                de una visita anterior en la que no se pudo generar el turno,
                                porque la sede no tenía colas configuradas. Las fotos ya están:
                                no hay que volver a tomarlas.
                            </p>

                            <div class="sispam-visita-abierta__acciones">
                                <x-filament::button
                                    color="danger"
                                    icon="heroicon-m-ticket"
                                    type="button"
                                    wire:click="completarFormulaSinTurno"
                                    wire:loading.attr="disabled"
                                    wire:target="completarFormulaSinTurno"
                                >
                                    Generar el turno de esa fórmula
                                </x-filament::button>
                            </div>
                        </x-filament::section>
                    @endif

                    {{-- Ya tiene una visita viva: casi nunca hace falta otro turno --}}
                    @if ($this->visitaAbierta)
                        @php($abierta = $this->visitaAbierta)
                        <x-filament::section
                            icon="heroicon-o-exclamation-triangle"
                            icon-color="warning"
                            class="sispam-visita-abierta"
                        >
                            <x-slot name="heading">{{ $abierta->titulo() }}</x-slot>

                            <p class="text-sm">
                                <span class="font-bold">Turno {{ $abierta->ticket->turno }}</span>
                                · {{ $abierta->ticket->numero }}
                                · {{ $abierta->estado() }}
                            </p>

                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                {{ $abierta->detalle() }}
                            </p>

                            <div class="sispam-visita-abierta__acciones">
                                @if ($this->puedeImprimir())
                                    <x-filament::button
                                        tag="a"
                                        :href="route('tickets.imprimir', $abierta->ticket)"
                                        target="_blank"
                                        icon="heroicon-m-printer"
                                        color="warning"
                                    >
                                        Reimprimir ese ticket
                                    </x-filament::button>
                                @endif

                                <x-filament::button
                                    color="gray"
                                    icon="heroicon-m-plus"
                                    type="button"
                                    wire:click="sumarAVisitaAbierta"
                                    wire:loading.attr="disabled"
                                    wire:target="sumarAVisitaAbierta"
                                >
                                    Sumar estas fotos a esa visita
                                </x-filament::button>
                            </div>
                        </x-filament::section>
                    @endif

                    {{-- Qué cambiaría al guardar --}}
                    @if (filled($cambios))
                        <x-filament::section
                            collapsible
                            collapsed
                            icon="heroicon-o-arrows-right-left"
                            icon-color="warning"
                        >
                            <x-slot name="heading">Cambios frente a lo registrado ({{ count($cambios) }})</x-slot>

                            <ul class="list-disc space-y-1 ps-5 text-sm">
                                @foreach ($cambios as $etiqueta => $cambio)
                                    <li><span class="font-semibold">{{ $etiqueta }}:</span> {{ $cambio }}</li>
                                @endforeach
                            </ul>
                        </x-filament::section>
                    @endif

                    {{-- Contacto, fórmula y prioridad --}}
                    @php($accionDeGenerar = $this->visitaAbierta ? 'generarDeTodosModos' : 'generarTicket')

                    <form wire:submit="{{ $accionDeGenerar }}" class="sispam-orientacion__visita">
                        {{ $this->formularioVisita }}

                        {{-- Si ya tiene una visita viva, el botón lo dice: generar
                             otro turno es una decisión, no el camino por defecto. --}}
                        <div class="sispam-orientacion__generar">
                            <x-filament::button
                                type="submit"
                                size="xl"
                                icon="heroicon-m-ticket"
                                :color="$this->visitaAbierta ? 'gray' : 'primary'"
                                wire:loading.attr="disabled"
                                wire:target="{{ $accionDeGenerar }}"
                            >
                                <span wire:loading.remove wire:target="{{ $accionDeGenerar }}">
                                    {{ $this->visitaAbierta ? 'Generar otro ticket de todos modos' : 'Generar ticket' }}
                                </span>
                                <span wire:loading wire:target="{{ $accionDeGenerar }}">Generando…</span>
                            </x-filament::button>
                        </div>
                    </form>
                </div>
            @endif
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
