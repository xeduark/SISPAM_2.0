<x-filament-panels::page>

    <form wire:submit="continuar">
        <x-filament::section icon="heroicon-o-identification" icon-color="primary">
            <x-slot name="heading">Antes de consultar</x-slot>
            <x-slot name="description">
                Se pregunta una sola vez por sesión. Si otra persona va a usar este equipo,
                que cierre la sesión o use «Cambiar de persona».
            </x-slot>

            {{ $this->form }}

            <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                <x-filament::button
                    type="submit"
                    size="lg"
                    icon="heroicon-m-arrow-right"
                    icon-position="after"
                    wire:loading.attr="disabled"
                >
                    Continuar
                </x-filament::button>
            </div>
        </x-filament::section>
    </form>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Por seguridad, la sesión se cierra sola a los {{ $this->minutosSinUso }} minutos sin uso.
    </p>

</x-filament-panels::page>
