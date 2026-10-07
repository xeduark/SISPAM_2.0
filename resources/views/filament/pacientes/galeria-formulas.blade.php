{{--
    Las fórmulas del paciente, agrupadas por visita.

    Las miniaturas y los originales salen del disco privado por rutas que
    exigen sesión y el permiso `orientacion.ver_orden`. Nunca hay URL pública
    para un dato de salud.

    El visor es Alpine —que ya viene con Filament— sin librerías nuevas: abre
    la imagen grande, con zoom y giro. Los PDF se abren en el visor del
    navegador, en otra pestaña.
--}}
@php
    $visitas = $getRecord()->formulasPorVisita();
@endphp

<div
    class="sispam-galeria"
    x-data="{
        abierta: null,
        escala: 1,
        giro: 0,
        abrir(url, titulo) {
            this.abierta = { url, titulo };
            this.escala = 1;
            this.giro = 0;
        },
        cerrar() { this.abierta = null },
        acercar() { this.escala = Math.min(5, this.escala + 0.25) },
        alejar() { this.escala = Math.max(0.25, this.escala - 0.25) },
        girar() { this.giro = (this.giro + 90) % 360 },
        restablecer() { this.escala = 1; this.giro = 0 },
    }"
    x-on:keydown.escape.window="cerrar()"
>
    @forelse ($visitas as $clave => $hojas)
        @php($ticket = $hojas->first()->ticket)

        <div class="sispam-galeria__visita">
            <div class="sispam-galeria__encabezado">
                <div>
                    <p class="font-bold">
                        @if ($ticket)
                            Turno {{ $ticket->turno }}
                            <span class="font-normal text-gray-500 dark:text-gray-400">
                                · {{ $ticket->numero }}
                            </span>
                        @else
                            Fórmulas sin ticket
                        @endif
                    </p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        @if ($ticket)
                            {{ $ticket->sede?->etiqueta }} ·
                            {{ $ticket->fecha?->format('d/m/Y') }}
                        @else
                            Se cargaron cuando la sede no tenía colas configuradas.
                        @endif
                        · {{ trans_choice('{1} :count hoja|[2,*] :count hojas', $hojas->count(), ['count' => $hojas->count()]) }}
                    </p>
                </div>

                @if ($ticket)
                    <x-filament::badge :color="\App\Models\Ticket::COLORES_ESTADO[$ticket->estado] ?? 'gray'">
                        {{ \App\Models\Ticket::ESTADOS[$ticket->estado] ?? $ticket->estado }}
                    </x-filament::badge>
                @endif
            </div>

            <div class="sispam-galeria__hojas">
                @foreach ($hojas as $hoja)
                    @php($url = route('soportes.orden-medica', $hoja))

                    @if ($hoja->esPdf())
                        {{-- Los PDF se abren en el visor del navegador: abrirlos
                             queda en la auditoría, como cualquier fórmula. --}}
                        <a
                            href="{{ $url }}"
                            target="_blank"
                            rel="noopener"
                            class="sispam-galeria__hoja sispam-galeria__hoja--pdf"
                            title="Abrir el PDF de la hoja {{ $hoja->pagina }}"
                        >
                            <x-filament::icon
                                icon="heroicon-o-document-text"
                                class="h-10 w-10 text-gray-400"
                            />
                            <span class="text-xs font-semibold">PDF</span>
                            <span class="sispam-galeria__pagina">{{ $hoja->pagina }}</span>
                        </a>
                    @else
                        <button
                            type="button"
                            class="sispam-galeria__hoja"
                            x-on:click="abrir(@js($url), @js('Hoja '.$hoja->pagina))"
                            title="Ver la hoja {{ $hoja->pagina }} en grande"
                        >
                            <img
                                src="{{ route('soportes.miniatura', $hoja) }}"
                                alt="Hoja {{ $hoja->pagina }} de la fórmula"
                                loading="lazy"
                            >
                            <span class="sispam-galeria__pagina">{{ $hoja->pagina }}</span>
                        </button>
                    @endif
                @endforeach
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Este paciente todavía no tiene fórmulas cargadas.
        </p>
    @endforelse

    {{-- El visor --}}
    <template x-if="abierta">
        <div class="sispam-visor" x-on:click.self="cerrar()">
            <div class="sispam-visor__barra">
                <span class="sispam-visor__titulo" x-text="abierta.titulo"></span>

                <div class="sispam-visor__botones">
                    <button type="button" x-on:click="alejar()" title="Alejar">−</button>
                    <button type="button" x-on:click="restablecer()" title="Tamaño original">
                        <span x-text="Math.round(escala * 100) + '%'"></span>
                    </button>
                    <button type="button" x-on:click="acercar()" title="Acercar">+</button>
                    <button type="button" x-on:click="girar()" title="Girar 90°">⟳</button>
                    <a :href="abierta.url" target="_blank" rel="noopener" title="Abrir en otra pestaña">↗</a>
                    <button type="button" x-on:click="cerrar()" title="Cerrar">✕</button>
                </div>
            </div>

            <div class="sispam-visor__lienzo">
                <img
                    :src="abierta.url"
                    :style="'transform: scale(' + escala + ') rotate(' + giro + 'deg)'"
                    alt=""
                >
            </div>
        </div>
    </template>
</div>
