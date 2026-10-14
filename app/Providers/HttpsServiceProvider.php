<?php

namespace App\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Que todo salga con https en producción, haya o no un proxy delante.
 *
 * - **Si APP_URL empieza por https**, todas las URLs que arma Laravel salen
 *   con https: los estilos y scripts del panel, la de Livewire y la del
 *   regreso de Authentik. Sin esto, detrás de un proxy que termina el TLS la
 *   aplicación ve http y arma enlaces http: el navegador los bloquea como
 *   contenido mixto y el panel queda sin estilos.
 * - **Si hay proxy** (`TRUSTED_PROXIES`), se le cree el `X-Forwarded-Proto`
 *   y el `X-Forwarded-For`: Laravel sabe que la petición llegó por https y la
 *   auditoría guarda la IP de la persona, no la del proxy.
 *
 * Las cookies solo viajan por https con `SESSION_SECURE_COOKIE=true` en el .env.
 */
class HttpsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $proxies = trim((string) config('sispam.proxies_de_confianza'));

        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
