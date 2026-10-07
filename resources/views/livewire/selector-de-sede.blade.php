{{--
    Selector de sede de la barra superior.

    Solo se pinta si la persona tiene más de una sede donde trabajar. Usa el
    dropdown de Filament, así que hereda el tema claro/oscuro y la paleta
    institucional sin CSS propio.
--}}
<div>
    @if ($visible)
        <x-filament::dropdown placement="bottom-end" teleport>
            <x-slot name="trigger">
                <button type="button" class="sispam-selector-sede" title="Cambiar de sede">
                    <x-filament::icon icon="heroicon-m-map-pin" class="h-4 w-4" />
                    <span class="sispam-selector-sede__nombre">
                        {{ $actual?->etiqueta ?? 'Sin sede' }}
                    </span>
                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 opacity-60" />
                </button>
            </x-slot>

            <x-filament::dropdown.header icon="heroicon-m-building-office-2">
                Trabajar en
            </x-filament::dropdown.header>

            <x-filament::dropdown.list>
                @foreach ($disponibles as $sede)
                    <x-filament::dropdown.list.item
                        :icon="$sede->is($actual) ? 'heroicon-m-check-circle' : 'heroicon-m-building-storefront'"
                        :color="$sede->is($actual) ? 'primary' : 'gray'"
                        wire:click="cambiar({{ $sede->getKey() }})"
                        wire:loading.attr="disabled"
                    >
                        {{ $sede->etiqueta }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    @endif
</div>
