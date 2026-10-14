<?php

namespace App\Http\Middleware;

use App\Models\Rol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Para las rutas que no pasan por `User::puede()` porque no tienen sesión:
 * la sala de espera es pública. Si su módulo está apagado en esta
 * instalación (`SISPAM_MODULOS`), responde 404, como si no existiera.
 *
 *   Route::middleware('modulo:turnos')->…
 */
final class ExigirModuloEncendido
{
    public function handle(Request $request, Closure $next, string $modulo): Response
    {
        abort_unless(Rol::moduloEncendido($modulo), 404);

        return $next($request);
    }
}
