<?php

namespace App\Filament\Pages\Auth;

use Filament\Facades\Filament;
use Filament\Pages\SimplePage;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Login del panel: la autenticación la hace Authentik. Esta página solo muestra
 * el botón que inicia el flujo OIDC (ver AuthentikController).
 */
class Login extends SimplePage
{
    /**
     * @var view-string
     */
    protected static string $view = 'filament.pages.auth.login';

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Iniciar sesión';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Iniciar sesión';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Ingresa con tu cuenta institucional de Authentik.';
    }
}
