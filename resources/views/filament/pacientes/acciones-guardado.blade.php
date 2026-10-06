<div
    wire:loading.class="pointer-events-none opacity-60"
    wire:target="create,save,createAnother"
>
    <x-filament::actions :actions="$livewire->accionesDeGuardado()" />
</div>
