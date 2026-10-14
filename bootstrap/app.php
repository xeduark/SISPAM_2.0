<?php

use App\Http\Middleware\ExigirModuloEncendido;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Aquí no hay ruta «login»: el inicio de sesión es el del panel, que
        // redirige a Authentik. Sin esto, cualquier ruta con middleware `auth`
        // responde 500 en vez de mandar al login.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

        // Rutas públicas de un módulo que se puede apagar (`SISPAM_MODULOS`).
        $middleware->alias(['modulo' => ExigirModuloEncendido::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
