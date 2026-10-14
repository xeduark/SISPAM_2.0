<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LogoutResponse;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra la sesión de una cuenta compartida tras un rato sin uso.
 *
 * Estas cuentas quedan abiertas en equipos que usa más de una persona. Si la
 * sesión no se cerrara, el último paciente consultado seguiría a la vista y
 * cualquiera que pasara por el equipo podría seguir consultando.
 *
 * ## Dos relojes que miden lo mismo
 *
 * - **En el navegador** (`filament/cuenta-compartida/cierre-por-inactividad`):
 *   cuando se cumple el plazo, la pantalla cierra sesión sola. Es lo que
 *   borra de la vista al paciente, y pasa aunque nadie toque el equipo.
 * - **Aquí, en el servidor**: es la garantía. Si el equipo estaba suspendido o
 *   el navegador cerrado, el reloj del navegador no corrió; la siguiente
 *   petición llega tarde y se cierra.
 *
 * Los dos cuentan como uso **cada petición** (abrir una pantalla, consultar),
 * así que dicen lo mismo.
 *
 * ## Por qué a Livewire no se le cierra la sesión aquí
 *
 * Cerrar sesión termina en Authentik (`LogoutResponse`), y una petición de
 * Livewire no puede seguir una redirección a otro dominio. A ella se le
 * responde 419 —«la página expiró»— sin tocar nada; el navegador recarga, y
 * esa recarga sí llega aquí como una página normal y cierra todo, Authentik
 * incluido.
 *
 * Corre también en las peticiones de Livewire (`isPersistent` en el panel):
 * consultar es una de ellas y tiene que contar como uso.
 */
final class CerrarCuentaCompartidaInactiva
{
    /** Cuándo fue la última petición, en segundos Unix. */
    public const SESION = 'cuenta_compartida.ultima_actividad';

    /** Lo que manda la pantalla al cerrar sesión por su cuenta. */
    public const MOTIVO = 'inactividad';

    private const ATRIBUTO = 'sispam.cierre_por_inactividad';

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        // Cerrar sesión siempre se deja pasar: es lo que hace la pantalla
        // cuando se cumple el plazo, y tiene que terminar en Authentik.
        if (! $usuario?->cuenta_compartida || $request->routeIs('filament.admin.auth.logout')) {
            return $next($request);
        }

        $ultima = $request->session()->get(self::SESION);

        if (is_int($ultima) && now()->getTimestamp() - $ultima >= self::minutos() * 60) {
            if ($request->hasHeader('X-Livewire')) {
                abort(419, 'La sesión se cerró por inactividad.');
            }

            return $this->cerrar($request);
        }

        $request->session()->put(self::SESION, now()->getTimestamp());

        return $next($request);
    }

    public static function minutos(): int
    {
        return max(1, (int) config('auth.cuenta_compartida.minutos_sin_uso', 30));
    }

    /**
     * Si el cierre de sesión en curso fue por inactividad, para que la
     * auditoría lo diga así y no como un «Cerró sesión» de la persona.
     */
    public static function esCierrePorInactividad(Request $request): bool
    {
        return $request->attributes->get(self::ATRIBUTO) === true
            || $request->input('motivo') === self::MOTIVO;
    }

    private function cerrar(Request $request): Response
    {
        $request->attributes->set(self::ATRIBUTO, true);

        Filament::auth()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Queda en la sesión nueva: se ve al volver a la pantalla de ingreso.
        Notification::make()
            ->title('La sesión se cerró por inactividad')
            ->body('Pasaron '.self::minutos().' minutos sin uso. Vuelve a iniciar sesión.')
            ->warning()
            ->persistent()
            ->send();

        return app(LogoutResponse::class)->toResponse($request);
    }
}
