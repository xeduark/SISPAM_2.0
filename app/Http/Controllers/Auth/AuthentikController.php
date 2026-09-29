<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Exception;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Inicio de sesión por Authentik (OIDC). El username de Authentik (`preferred_username`)
 * es el documento de identidad y debe existir previamente en la tabla users.
 */
class AuthentikController extends Controller
{
    public function redirect(): SymfonyRedirectResponse
    {
        $config = config('services.authentik');

        if (blank($config['base_url']) || blank($config['client_id']) || blank($config['client_secret'])) {
            return $this->rechazar('El inicio de sesión con Authentik no está configurado. Comunícate con un administrador.');
        }

        return Socialite::driver('authentik')->redirect();
    }

    public function callback(): RedirectResponse
    {
        $panel = Filament::getPanel('admin');

        try {
            $datos = Socialite::driver('authentik')->user()->getRaw();
        } catch (Exception $exception) {
            report($exception);

            return $this->rechazar('No fue posible validar tu inicio de sesión con Authentik. Intenta de nuevo.');
        }

        $documento = $datos['preferred_username'] ?? null;
        $user = filled($documento) ? User::where('documento', $documento)->first() : null;

        if (! $user) {
            return $this->rechazar("El usuario «{$documento}» no está registrado en SISPAM. Solicita acceso a un administrador.");
        }

        if (! $user->canAccessPanel($panel)) {
            return $this->rechazar('Tu usuario está inactivo. Comunícate con un administrador.');
        }

        $panel->auth()->login($user);
        session()->regenerate();

        return redirect()->intended($panel->getUrl());
    }

    private function rechazar(string $mensaje): RedirectResponse
    {
        Notification::make()
            ->title('Acceso denegado')
            ->body($mensaje)
            ->danger()
            ->persistent()
            ->send();

        return redirect()->to(Filament::getPanel('admin')->getLoginUrl());
    }
}
