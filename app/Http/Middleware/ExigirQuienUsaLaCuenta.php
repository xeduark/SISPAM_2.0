<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Identificarse;
use App\Services\CuentaCompartida\QuienUsaLaCuenta;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Con una cuenta compartida, nadie trabaja sin decir antes quién es.
 *
 * Cualquier pantalla del panel lleva a «¿Quién está usando esta cuenta?»
 * hasta que la persona escriba su nombre y su documento. Se pide una vez por
 * sesión (`QuienUsaLaCuenta`).
 *
 * Corre también en las peticiones de Livewire (`isPersistent` en el panel).
 * Aun así, `ConsultarPaciente::buscar()` lo vuelve a comprobar: un método de
 * Livewire se puede llamar sin pasar por la pantalla, y consultar es lo que
 * más importa dejar a nombre de alguien.
 */
final class ExigirQuienUsaLaCuenta
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! QuienUsaLaCuenta::falta($request->user())) {
            return $next($request);
        }

        if ($request->routeIs(Identificarse::getRouteName('admin'), 'filament.admin.auth.logout')) {
            return $next($request);
        }

        abort_if($request->hasHeader('X-Livewire'), 403, 'Antes de continuar, di quién está usando la cuenta.');

        redirect()->setIntendedUrl($request->fullUrl());

        return redirect()->to(Identificarse::getUrl(panel: 'admin'));
    }
}
