<?php

namespace App\Filament\Pages;

use App\Http\Middleware\CerrarCuentaCompartidaInactiva;
use App\Services\CuentaCompartida\QuienUsaLaCuenta;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * «¿Quién está usando esta cuenta?»: lo primero que ve quien entra con una
 * cuenta compartida.
 *
 * La persona escribe su nombre y su documento, y desde ahí cada línea de la
 * auditoría lleva la cuenta, la sede y esa persona. Se pide una vez por
 * sesión; `ExigirQuienUsaLaCuenta` trae aquí desde cualquier pantalla hasta
 * que se responda.
 *
 * Solo la ven las cuentas compartidas; a las demás les responde 403.
 */
class Identificarse extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'identificarse';

    protected static ?string $title = '¿Quién está usando esta cuenta?';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.identificarse';

    /** @var array<string, mixed> */
    public array $datos = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->cuenta_compartida;
    }

    public function mount(): void
    {
        // Ya dijo quién es: «Cambiar de persona» es el camino para volver aquí.
        if (! QuienUsaLaCuenta::falta()) {
            $this->redirect(filament()->getUrl());

            return;
        }

        $this->form->fill();
    }

    public function getSubheading(): ?string
    {
        $sede = auth()->user()?->sede?->etiqueta;

        return 'Esta cuenta la comparten varias personas'.($sede ? " de {$sede}" : '').'. '
            .'Escribe tu nombre y tu documento: quedan en el registro de cada consulta que hagas.';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('nombre')
                    ->label('Tu nombre completo')
                    ->required()
                    ->minLength(5)
                    ->maxLength(160)
                    ->regex("/^[\\pL\\s'.-]+$/u")
                    ->validationMessages([
                        'regex' => 'El nombre solo admite letras y espacios.',
                    ])
                    ->autocomplete('off')
                    ->autofocus(),
                Forms\Components\TextInput::make('documento')
                    ->label('Tu número de documento')
                    ->required()
                    ->regex('/^[A-Za-z0-9]{5,15}$/')
                    ->validationMessages([
                        'regex' => 'Escribe el documento sin puntos ni espacios: de 5 a 15 letras o números.',
                    ])
                    ->autocomplete('off'),
            ])
            ->columns(['default' => 1, 'sm' => 2])
            ->statePath('datos');
    }

    public function continuar(): void
    {
        $datos = $this->form->getState();

        QuienUsaLaCuenta::identificar($datos['nombre'], $datos['documento']);

        $this->redirectIntended(filament()->getUrl());
    }

    public function getMinutosSinUsoProperty(): int
    {
        return CerrarCuentaCompartidaInactiva::minutos();
    }
}
