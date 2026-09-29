<?php

namespace App\Http\Responses;

use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LogoutResponse as Responsable;
use Illuminate\Http\RedirectResponse;

/**
 * Al cerrar sesión en SISPAM también se cierra la sesión en Authentik, para que en
 * equipos compartidos el siguiente usuario no entre con la sesión del anterior.
 */
class LogoutResponse implements Responsable
{
    public function toResponse($request): RedirectResponse
    {
        $baseUrl = config('services.authentik.base_url');
        $slug = config('services.authentik.app_slug');

        if (blank($baseUrl) || blank($slug)) {
            return redirect()->to(Filament::getLoginUrl());
        }

        return redirect()->away(rtrim($baseUrl, '/')."/application/o/{$slug}/end-session/");
    }
}
