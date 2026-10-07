<x-filament-panels::page>
    @php
        $t = $this->record;
        $verOriginal = auth()->user()?->puede('orientacion.ver_orden');
        $esPdf = str_ends_with(strtolower((string) $t->soporte?->orden_medica), '.pdf');
        $urlOriginal = $t->soporte_id ? route('soportes.orden-medica', $t->soporte_id) : null;
    @endphp

    {{-- Estado y verificaciones arriba, a todo lo ancho --}}
    <div class="flex flex-wrap items-center gap-2 text-sm">
        <x-filament::badge :color="\App\Models\Transcripcion::COLORES_ESTADO[$t->estado] ?? 'gray'">
            {{ \App\Models\Transcripcion::ESTADOS[$t->estado] ?? $t->estado }}
        </x-filament::badge>
        <span class="text-gray-600 dark:text-gray-300">{{ $t->paciente?->documento_completo }} · {{ $t->paciente?->nombre_completo }}</span>
        @if ($t->ticket)
            <x-filament::badge color="gray">Turno {{ $t->ticket->turno }} · {{ $t->ticket->numero }}</x-filament::badge>
        @endif
        @if ($t->verificacion_cedula)
            <x-filament::badge :color="$t->verificacion_cedula === \App\Models\Transcripcion::CEDULA_NO_ENCONTRADA ? 'warning' : 'success'">
                {{ \App\Models\Transcripcion::VERIFICACIONES_CEDULA[$t->verificacion_cedula] ?? $t->verificacion_cedula }}
            </x-filament::badge>
        @endif
        @if ($calidad = $t->formula['calidad_lectura'] ?? null)
            <x-filament::badge :color="$calidad === 'ALTA' ? 'success' : 'warning'">Lectura {{ strtolower($calidad) }}</x-filament::badge>
        @endif
        @if ($t->estado === \App\Models\Transcripcion::ESTADO_EN_REVISION && ! $this->esMia())
            <x-filament::badge color="danger">La está revisando {{ $t->tomadaPor?->nombre }}</x-filament::badge>
        @endif
        @if ($t->estado === \App\Models\Transcripcion::ESTADO_RECHAZADA)
            <x-filament::badge color="danger">{{ \App\Models\Transcripcion::MOTIVOS_RECHAZO[$t->motivo_rechazo] ?? $t->motivo_rechazo }}{{ $t->detalle_rechazo ? ': '.$t->detalle_rechazo : '' }}</x-filament::badge>
        @endif
    </div>

    @if ($t->enRectificacion())
        <x-filament::section compact icon="heroicon-o-pencil-square" icon-color="warning">
            <x-slot name="heading">Rectificando la versión {{ $t->version }} (ya estaba confirmada)</x-slot>
            <p class="text-sm">Motivo: <strong>{{ $t->formula['rectificacion']['motivo'] }}</strong>. Al confirmar, la orden sale como versión {{ $t->version + 1 }} y el antes → después queda en la auditoría.</p>
        </x-filament::section>
    @elseif ($t->version > 1)
        <p class="text-sm text-gray-500">Versión {{ $t->version }}: fue rectificada (ver Auditoría).</p>
    @endif

    @php
        $vista = in_array($t->estado, [\App\Models\Transcripcion::ESTADO_EN_COLA, \App\Models\Transcripcion::ESTADO_FALLIDA], true) ? null : $this->previsualizacion();
        $editable = $this->esMia();
        $colores = [
            'ventanilla' => 'success', 'parcial' => 'warning', 'domicilio' => 'info',
            'no_dispensa' => 'danger', 'sin_producto' => 'gray', 'sin_cantidad' => 'gray',
        ];
    @endphp

    {{-- ARRIBA: la fórmula original y el acta que se generará, lado a lado --}}
    <div class="sispam-transcribir">
        <x-filament::section compact>
            <x-slot name="heading">Fórmula original</x-slot>
            @if (! $verOriginal || ! $urlOriginal)
                <p class="sispam-acta__vacio">
                    Para ver la fórmula original tu rol necesita el permiso «Orientación → Ver la orden médica».
                </p>
            @elseif ($esPdf)
                <iframe src="{{ $urlOriginal }}" class="sispam-transcribir__panel" title="Fórmula original"></iframe>
            @else
                <div x-data="{ zoom: 1, giro: 0 }">
                    <div class="sispam-transcribir__herramientas">
                        <x-filament::icon-button icon="heroicon-m-magnifying-glass-plus" label="Acercar" x-on:click="zoom = Math.min(zoom + 0.25, 4)" />
                        <x-filament::icon-button icon="heroicon-m-magnifying-glass-minus" label="Alejar" x-on:click="zoom = Math.max(zoom - 0.25, 0.5)" />
                        <x-filament::icon-button icon="heroicon-m-arrow-uturn-right" label="Rotar" x-on:click="giro = (giro + 90) % 360" />
                        <x-filament::icon-button icon="heroicon-m-arrows-pointing-in" label="Restablecer" x-on:click="zoom = 1; giro = 0" />
                        <x-filament::link :href="$urlOriginal" target="_blank" icon="heroicon-m-arrow-top-right-on-square" size="sm" style="margin-inline-start:auto">Abrir aparte</x-filament::link>
                    </div>
                    {{-- wire:ignore: la previsualización se refresca con cada cambio y no debe reiniciar el zoom --}}
                    <div wire:ignore class="sispam-transcribir__panel sispam-transcribir__imagen">
                        <img src="{{ $urlOriginal }}" alt="Fórmula original"
                             x-bind:style="`transform: scale(${zoom}) rotate(${giro}deg); transform-origin: ${giro ? 'center' : 'top left'}`">
                    </div>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section compact>
            <x-slot name="heading">Previsualización del acta</x-slot>
            <x-slot name="description">Con lo que hay en pantalla, sin guardar.</x-slot>

            @if ($t->estado === \App\Models\Transcripcion::ESTADO_EN_COLA)
                <p class="sispam-acta__vacio">Leyendo la fórmula… Recarga en unos segundos (requiere <code>queue:work</code>).</p>
            @elseif ($t->estado === \App\Models\Transcripcion::ESTADO_FALLIDA)
                <p class="sispam-acta__vacio sispam-acta__vacio--error">No se pudo leer: {{ $t->error }}</p>
            @else
                <div class="sispam-transcribir__panel sispam-acta">
                    @foreach ($vista['formulas'] as $f)
                        <div @class(['sispam-acta__formula', 'sispam-acta__formula--rechazada' => ! empty($f['rechazada'])])>
                            <div class="sispam-acta__titulo">
                                Fórmula #{{ $f['numero'] ?? '?' }} · {{ filled($f['ips'] ?? null) ? $f['ips'] : 'IPS sin registrar' }}
                            </div>
                            <div class="sispam-acta__sub">
                                {{ filled($f['medico']['nombre'] ?? null) ? $f['medico']['nombre'] : 'Médico sin registrar' }}
                                @if (filled($f['cie10_principal']['codigo'] ?? null)) · CIE-10 {{ $f['cie10_principal']['codigo'] }} @endif
                            </div>
                            @if (! empty($f['rechazada']))
                                <div class="sispam-acta__rechazo">
                                    NO SE DISPENSA{{ filled($f['motivo_rechazo'] ?? null) ? ': '.(\App\Models\Transcripcion::MOTIVOS_RECHAZO_FORMULA[$f['motivo_rechazo']] ?? '') : ' — falta el motivo' }}{{ filled($f['detalle_rechazo'] ?? null) ? '. '.$f['detalle_rechazo'] : '' }}
                                </div>
                            @endif

                            <ul class="sispam-acta__lineas">
                                @forelse ($f['lineas'] as $l)
                                    <li class="sispam-acta__linea">
                                        @if ($l['destino'] === 'no_dispensa')
                                            <span class="sispam-acta__check sispam-acta__check--nada">—</span>
                                        @else
                                            <button type="button"
                                                    @if ($editable) wire:click="alternarRevisado('{{ $l['clave'] }}')" @else disabled @endif
                                                    title="{{ $l['revisado'] ? 'Revisado: clic para desmarcar' : 'Marcar como revisado contra el original' }}"
                                                    @class(['sispam-acta__check', 'sispam-acta__check--ok' => $l['revisado']])>{{ $l['revisado'] ? '✓' : '' }}</button>
                                        @endif
                                        <div @class(['sispam-acta__producto', 'sispam-acta__producto--tachado' => $l['destino'] === 'no_dispensa'])>
                                            <div class="sispam-acta__nombre">{{ $l['producto'] }}</div>
                                            @if ($l['producto'] !== $l['prescrito'])
                                                <div class="sispam-acta__sub">Rx: {{ $l['prescrito'] }}</div>
                                            @endif
                                        </div>
                                        <span class="sispam-acta__cantidad">{{ $l['cantidad'] ?: '—' }}</span>
                                        <x-filament::badge size="sm" :color="$colores[$l['destino']] ?? 'gray'">{{ $l['etiqueta'] }}</x-filament::badge>
                                    </li>
                                @empty
                                    <li class="sispam-acta__sub">Sin medicamentos en esta fórmula.</li>
                                @endforelse
                            </ul>
                        </div>
                    @endforeach

                    @if ($vista['huerfanas'] !== [])
                        <div class="sispam-acta__formula sispam-acta__formula--aviso">
                            {{ count($vista['huerfanas']) }} medicamento(s) asignados a una fórmula que no existe. Corrígelos abajo.
                        </div>
                    @endif
                </div>

                <div class="sispam-acta__resumen">
                    <x-filament::badge color="success">{{ $vista['ventanilla'] }} ventanilla</x-filament::badge>
                    @if ($vista['parcial']) <x-filament::badge color="warning">{{ $vista['parcial'] }} parcial</x-filament::badge> @endif
                    <x-filament::badge color="info">{{ $vista['domicilio'] }} domicilio</x-filament::badge>
                    @if ($vista['noDispensa']) <x-filament::badge color="danger">{{ $vista['noDispensa'] }} no se dispensa</x-filament::badge> @endif
                    <x-filament::badge :color="$vista['porRevisar'] ? 'warning' : 'success'" style="margin-inline-start:auto">
                        Revisadas {{ $vista['revisadas'] }} de {{ $vista['revisadas'] + $vista['porRevisar'] }}
                    </x-filament::badge>
                </div>
                @unless ($vista['stockConsultado'])
                    <p class="sispam-acta__sub" style="margin-top:8px">No se consultó el inventario de la sede: todo aparece en ventanilla y farmacia confirma al alistar.</p>
                @endunless
            @endif
        </x-filament::section>
    </div>

    {{-- ABAJO, a todo lo ancho: fórmulas y medicamentos --}}
    @if (! $this->esMia() && in_array($t->estado, [\App\Models\Transcripcion::ESTADO_LEIDA, \App\Models\Transcripcion::ESTADO_EN_REVISION], true))
        <x-filament::section compact>
            <p class="text-sm text-gray-500">Solo lectura. Usa «Tomar para revisar» para corregir y confirmar.</p>
        </x-filament::section>
    @endif

    @if ($faltas !== [])
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <x-slot name="heading">Antes de confirmar</x-slot>
            <ul class="list-disc space-y-1 pl-5 text-sm">
                @foreach ($faltas as $falta)
                    <li>{{ $falta }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{ $this->form }}
</x-filament-panels::page>
