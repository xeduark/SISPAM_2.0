<x-filament-panels::page>

    {{-- Buscador --}}
    <form wire:submit="buscar">
        <x-filament::section>
            <x-slot name="heading">Buscar afiliado</x-slot>
            <x-slot name="description">
                Escribe el documento del paciente y consulta sus datos en Savia Salud EPS.
            </x-slot>

            {{ $this->form }}

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="buscar">Consultar en Savia</span>
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

    {{-- Mientras responde el servicio --}}
    <div wire:loading wire:target="buscar" class="text-sm text-gray-500 dark:text-gray-400">
        Consultando el servicio de Savia Salud EPS…
    </div>

    <div wire:loading.remove wire:target="buscar">

        {{-- El afiliado no se pudo traer --}}
        @if ($consultado && ! $afiliado && $diagnostico)
            <x-filament::section
                :icon="$diagnostico['tono'] === 'warn' ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-x-circle'"
                :icon-color="$diagnostico['tono'] === 'warn' ? 'warning' : 'danger'"
            >
                <x-slot name="heading">{{ $diagnostico['titulo'] }}</x-slot>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $diagnostico['detalle'] }}</p>
            </x-filament::section>
        @endif

        {{-- Resultado --}}
        @if ($afiliado)

            {{-- Encabezado: quién es, si está afiliado y si está en SISPAM --}}
            <x-filament::section>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                            {{ $this->nombreCompleto }}
                        </h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ $afiliado['tipoDocumentoAfiliado'] ?? '' }}
                            {{ $afiliado['documentoAfiliado'] ?? '' }}
                            @if (! empty($afiliado['fechaNacimientoAfiliado']))
                                · Nacimiento {{ $afiliado['fechaNacimientoAfiliado'] }}
                            @endif
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::badge :color="$this->colorEstado" size="lg">
                            Afiliación: {{ $this->estadoAfiliacion ?: 'sin estado' }}
                        </x-filament::badge>

                        @if (! empty($afiliado['regimen']))
                            <x-filament::badge color="gray" size="lg">
                                {{ $afiliado['regimen'] }}
                            </x-filament::badge>
                        @endif

                        <x-filament::badge :color="$pacienteId ? 'success' : 'gray'" size="lg">
                            {{ $pacienteId ? 'Registrado en SISPAM' : 'No registrado en SISPAM' }}
                        </x-filament::badge>
                    </div>
                </div>

                @if (! $this->estaActivo)
                    <div class="mt-4 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950/40 dark:text-warning-300">
                        <strong>Este afiliado no está activo en Savia.</strong>
                        El servicio reporta el estado «{{ $this->estadoAfiliacion ?: 'sin estado' }}»
                        @if (! empty($afiliado['causaEstado']))
                            ({{ $afiliado['causaEstado'] }})
                        @endif
                        . Verifícalo antes de dispensar.
                    </div>
                @endif

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <x-filament::button icon="heroicon-m-arrow-down-tray" wire:click="guardar" wire:loading.attr="disabled">
                        {{ $pacienteId ? 'Actualizar en SISPAM' : 'Registrar en SISPAM' }}
                    </x-filament::button>

                    @if ($pacienteId)
                        <x-filament::button
                            tag="a"
                            color="gray"
                            outlined
                            icon="heroicon-m-arrow-top-right-on-square"
                            :href="\App\Filament\Resources\PacienteResource::getUrl('view', ['record' => $pacienteId])"
                        >
                            Ver ficha en SISPAM
                        </x-filament::button>

                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            Registrado desde el {{ $registradoDesde }}
                        </span>
                    @endif
                </div>
            </x-filament::section>

            {{-- Programas especiales y RIAS --}}
            @if (count($this->programas))
                <x-filament::section icon="heroicon-o-heart" icon-color="primary">
                    <x-slot name="heading">Programas especiales y RIAS</x-slot>
                    <x-slot name="description">{{ count($this->programas) }} programa(s) asociados al afiliado.</x-slot>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($this->programas as $programa)
                            <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-white/5">
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $programa['tipo'] }}
                                </div>
                                <div class="mt-1 text-sm font-medium text-gray-950 dark:text-white">
                                    {{ $programa['descripcion'] }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            {{-- Todos los datos que devolvió el servicio, agrupados --}}
            @foreach ($this->grupos as $titulo => $campos)
                <x-filament::section collapsible :collapsed="$loop->index > 1">
                    <x-slot name="heading">{{ $titulo }}</x-slot>

                    <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($campos as $etiqueta => $valor)
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $etiqueta }}
                                </dt>
                                <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                                    {{ $valor }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </x-filament::section>
            @endforeach

            {{-- Campos que Savia devolvió y la V3 no documenta --}}
            @if (count($this->camposNuevos))
                <x-filament::section collapsible collapsed icon="heroicon-o-information-circle">
                    <x-slot name="heading">Campos nuevos no documentados</x-slot>
                    <x-slot name="description">
                        El servicio devolvió estos campos, que la especificación V3 no describe.
                    </x-slot>

                    <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($this->camposNuevos as $campo => $valor)
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $campo }}
                                </dt>
                                <dd class="mt-1 text-sm text-gray-950 dark:text-white">{{ $valor }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-filament::section>
            @endif
        @endif
    </div>

</x-filament-panels::page>
