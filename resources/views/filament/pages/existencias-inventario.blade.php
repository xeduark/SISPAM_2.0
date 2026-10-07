<x-filament-panels::page>
    <x-filament::section>
        <form wire:submit="consultar" class="flex flex-wrap items-end gap-4">
            <label class="grow text-sm">
                <span class="block text-gray-500">SKU (código del medicamento)</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="sku" placeholder="MX804-1" autofocus class="font-mono uppercase" />
                </x-filament::input.wrapper>
            </label>

            <label class="text-sm">
                <span class="block text-gray-500">Sede</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model="sede">
                        <option value="">Todas</option>
                        @foreach ($this->sedes as $codigo => $nombre)
                            <option value="{{ $codigo }}">{{ $nombre }} ({{ $codigo }})</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            <x-filament::button type="submit" icon="heroicon-m-magnifying-glass">Consultar</x-filament::button>
            <x-filament::link :href="$this->urlSistemaInventarios()" target="_blank" icon="heroicon-m-arrow-top-right-on-square" size="sm">
                Abrir el sistema de inventarios
            </x-filament::link>
        </form>
        @if ($aviso)
            <p class="mt-3 text-sm text-danger-600">{{ $aviso }}</p>
        @endif
    </x-filament::section>

    @if ($resultado)
        @php($p = $resultado['producto'])
        <x-filament::section>
            <x-slot name="heading">
                <span class="font-mono">{{ $p['codigo'] }}</span> · {{ $p['nombre_generico'] }} {{ $p['concentracion'] }}
            </x-slot>
            <x-slot name="description">
                {{ $p['forma_farmaceutica'] }} · {{ $p['presentacion_comercial'] }}
                @if ($p['unidades_por_presentacion'] > 1)
                    · {{ $p['unidades_por_presentacion'] }} {{ strtolower($p['unidad_minima']) }}(s) por presentación
                @endif
            </x-slot>
            <div class="flex flex-wrap gap-6 text-sm">
                <div><div class="text-gray-500">Para entregar hoy{{ $sede ? " en {$sede}" : '' }}</div><div class="text-3xl font-bold">{{ number_format($resultado['stock_dispensable']) }}</div></div>
                <div><div class="text-gray-500">Agrupador</div><div class="font-mono">{{ $p['codigo_agrupador'] }}</div></div>
                <div><div class="text-gray-500">CUM / ATC</div><div class="font-mono">{{ $p['codigo_cums'] ?? '—' }} / {{ $p['codigo_atc'] ?? '—' }}</div></div>
                @unless ($p['activo'])
                    <x-filament::badge color="danger">Producto inactivo</x-filament::badge>
                @endunless
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Lotes con stock ({{ count($resultado['lotes']) }})</x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b text-left text-gray-500">
                        <th class="py-2 pr-3">Lote</th><th class="py-2 pr-3">Bodega</th><th class="py-2 pr-3">Vence</th>
                        <th class="py-2 pr-3 text-right">Cantidad</th><th class="py-2">Se entrega</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($resultado['lotes'] as $l)
                        @php($dias = now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($l['fecha_vencimiento']), false))
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-3 font-mono text-xs">{{ $l['numero_lote'] }}</td>
                            <td class="py-2 pr-3">{{ $l['bodega'] }} <span class="text-xs text-gray-500">{{ $l['codigo_sede'] }}</span></td>
                            <td class="py-2 pr-3">
                                {{ \Illuminate\Support\Carbon::parse($l['fecha_vencimiento'])->format('d/m/Y') }}
                                @if ($dias <= 0)
                                    <x-filament::badge color="danger">Vencido</x-filament::badge>
                                @elseif ($dias <= 90)
                                    <x-filament::badge color="warning">{{ (int) $dias }} días</x-filament::badge>
                                @endif
                            </td>
                            <td class="py-2 pr-3 text-right font-semibold">{{ number_format($l['cantidad']) }}</td>
                            <td class="py-2">
                                @if ($l['dispensable'])
                                    <x-filament::badge color="success">Sí</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray">No ({{ $l['estado'] !== 'DISPONIBLE' ? strtolower($l['estado']) : ($dias <= 0 ? 'vencido' : 'bodega sin sede') }})</x-filament::badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-500">Sin stock{{ $sede ? " en {$sede}" : '' }}.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Kardex (últimos {{ count($resultado['movimientos']) }} movimientos)</x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b text-left text-gray-500">
                        <th class="py-2 pr-3">Fecha</th><th class="py-2 pr-3">Tipo</th><th class="py-2 pr-3">Lote / bodega</th>
                        <th class="py-2 pr-3 text-right">Cantidad</th><th class="py-2 pr-3 text-right">Stock</th><th class="py-2 pr-3">Referencia</th><th class="py-2">Usuario</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($resultado['movimientos'] as $m)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-3 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($m['fecha'])->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                            <td class="py-2 pr-3"><x-filament::badge :color="$m['cantidad'] < 0 ? 'danger' : 'success'">{{ str_replace('_', ' ', $m['tipo']) }}</x-filament::badge></td>
                            <td class="py-2 pr-3 text-xs">{{ $m['numero_lote'] }}<div class="text-gray-500">{{ $m['bodega'] }}</div></td>
                            <td class="py-2 pr-3 text-right font-semibold {{ $m['cantidad'] < 0 ? 'text-danger-600' : 'text-success-600' }}">{{ $m['cantidad'] > 0 ? '+' : '' }}{{ $m['cantidad'] }}</td>
                            <td class="py-2 pr-3 text-right text-xs text-gray-500">{{ $m['stock_anterior'] }} → {{ $m['stock_nuevo'] }}</td>
                            <td class="py-2 pr-3 font-mono text-xs">{{ $m['referencia'] }}</td>
                            <td class="py-2 text-xs">{{ $m['usuario'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-center text-gray-500">Sin movimientos.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
