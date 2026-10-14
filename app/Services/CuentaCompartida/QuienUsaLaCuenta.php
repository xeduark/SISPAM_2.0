<?php

namespace App\Services\CuentaCompartida;

use App\Models\Auditoria;
use App\Models\User;

/**
 * La persona que está usando una cuenta compartida en esta sesión.
 *
 * Las cuentas compartidas (`users.cuenta_compartida`) las usan varias personas
 * de una sede, así que la cuenta sola no dice quién hizo qué. Antes de
 * trabajar, la persona escribe su nombre y su documento; desde ahí cada línea
 * de la auditoría lleva la cuenta, la sede **y esa persona**
 * (`Auditoria::registrar()` lo toma de aquí).
 *
 * ## Vive en la sesión, no en la base
 *
 * Es de esta sesión y de este equipo: en otro equipo, otra persona puede estar
 * usando la misma cuenta al mismo tiempo. Se pide **una vez por sesión** y se
 * olvida al iniciar sesión, al cerrarla (Filament invalida la sesión) y al
 * cerrarse por inactividad.
 *
 * ## Solo para la auditoría
 *
 * No da permisos ni cambia lo que la cuenta ve. Lo que la persona escribe no
 * se comprueba contra nada: es su palabra, y queda registrada con la IP y el
 * navegador desde donde se dijo.
 */
final class QuienUsaLaCuenta
{
    public const SESION = 'cuenta_compartida.persona';

    /**
     * Si la cuenta es compartida y todavía nadie dijo quién la está usando.
     */
    public static function falta(?User $usuario = null): bool
    {
        $usuario ??= auth()->user();

        return (bool) $usuario?->cuenta_compartida && self::persona($usuario) === null;
    }

    /**
     * Quién dijo estar usando la cuenta, o null si no es compartida o nadie
     * se ha identificado.
     *
     * Solo responde por la cuenta con la que se entró: la sesión es de ella.
     *
     * @return array{nombre: string, documento: string}|null
     */
    public static function persona(?User $usuario = null): ?array
    {
        $actual = auth()->user();
        $usuario ??= $actual;

        if (! $usuario?->cuenta_compartida || ! $usuario->is($actual)) {
            return null;
        }

        $persona = session(self::SESION);

        if (! is_array($persona) || blank($persona['nombre'] ?? null) || blank($persona['documento'] ?? null)) {
            return null;
        }

        return ['nombre' => (string) $persona['nombre'], 'documento' => (string) $persona['documento']];
    }

    /**
     * Anota quién está usando la cuenta y lo deja en la auditoría.
     */
    public static function identificar(string $nombre, string $documento): void
    {
        $persona = [
            'nombre' => mb_substr((string) preg_replace('/\s+/u', ' ', trim($nombre)), 0, 160),
            'documento' => mb_substr(trim($documento), 0, 40),
        ];

        session()->put(self::SESION, $persona);

        $cuenta = auth()->user();

        // La línea ya sale con la persona: la lee de la sesión que se acaba de escribir.
        Auditoria::registrar(
            accion: Auditoria::ACCION_SE_IDENTIFICO,
            descripcion: 'Se identificó en la cuenta compartida '.($cuenta?->documento ?? ''),
            entidadTipo: 'usuario',
            entidadId: $cuenta?->getKey(),
        );
    }

    public static function olvidar(): void
    {
        session()->forget(self::SESION);
    }
}
