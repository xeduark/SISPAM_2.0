<?php

namespace App\Services\Authentik;

use App\Models\Auditoria;
use App\Models\Rol;
use App\Models\User;

/**
 * Le crea en Authentik la cuenta a quien se registra en SISPAM, para que pueda entrar.
 *
 * - **El username es el documento**, que es lo que busca el inicio de sesión
 *   (`AuthentikController`).
 * - **Los roles que se eligen en SISPAM son sus grupos allá.** Solo se tocan
 *   los grupos que son roles de SISPAM (tabla `roles`): los de otras
 *   aplicaciones del mismo Authentik quedan como estaban. Si el grupo de un
 *   rol no existe todavía, se crea.
 * - **Nunca asigna un grupo de superusuario de Authentik**, aunque se llame
 *   como un rol: le daría todo el Authentik, no solo SISPAM.
 * - **La contraseña es la que escribe quien lo crea.** SISPAM se la pasa a
 *   Authentik y no la guarda, no la muestra y no la registra.
 * - **Si ya tenía cuenta** (por ejemplo, la usa en otra aplicación), se usa
 *   esa: no se duplica. Su contraseña solo se cambia si esa cuenta la creó
 *   SISPAM; si no, conserva la suya, que puede usar en otras aplicaciones.
 * - **Desactivar o eliminar en SISPAM no toca Authentik.** El ingreso ya lo
 *   niega SISPAM (`User::canAccessPanel()` y el callback), y la cuenta puede
 *   servirle en otras aplicaciones.
 *
 * Todo lo que hace allá queda en la auditoría, sin la contraseña.
 */
class AprovisionarEnAuthentik
{
    /**
     * Marca en los atributos de la cuenta: la creó SISPAM. Solo a esas les
     * puede poner una contraseña nueva; las demás se manejan en Authentik.
     */
    public const ORIGEN = 'SISPAM';

    /** Al editar sus roles: en Authentik quedan exactamente los de SISPAM que se eligieron. */
    public const ROLES_EXACTOS = 'exactos';

    /**
     * Al crearlo: los elegidos se suman a los que ya tuviera allá. Si ya tenía
     * cuenta, nadie le quita roles sin verlos: el formulario nuevo no los muestra.
     */
    public const ROLES_AGREGAR = 'agregar';

    /** Solo cambió el documento: se renombra la cuenta y sus grupos no se tocan. */
    public const ROLES_NO_TOCAR = 'no_tocar';

    /** Largo mínimo de la contraseña que se escribe en SISPAM. */
    public const CLAVE_MINIMA = 8;

    public function __construct(private readonly ApiDeAuthentik $api) {}

    public function configurado(): bool
    {
        return $this->api->configurada();
    }

    /**
     * Que exista en Authentik con sus roles de SISPAM.
     *
     * Con `$documentoAnterior` (lo cambiaron en el formulario), se renombra la
     * cuenta que ya tenía en vez de crearle otra. `$roles` dice qué hacer con
     * sus grupos si ya tenía cuenta (`ROLES_*`); si no la tenía, se crea con
     * los roles elegidos. `$clave` es la que escribió quien lo creó; sin
     * ella, una cuenta nueva queda sin contraseña hasta que se le ponga.
     */
    public function asegurar(
        User $usuario,
        ?string $documentoAnterior = null,
        string $roles = self::ROLES_EXACTOS,
        #[\SensitiveParameter] ?string $clave = null,
    ): ResultadoAprovisionamiento {
        if (! $this->api->configurada()) {
            return ResultadoAprovisionamiento::sinConfigurar();
        }

        $grupos = $this->api->grupos();

        // Antes de tocar la cuenta: si un rol no se puede asignar (grupo de
        // superusuario), que falle sin haber cambiado nada allá.
        $deseados = $roles === self::ROLES_NO_TOCAR ? null : $this->gruposDeseados($usuario, $grupos);
        $cuenta = $this->buscarCuenta($usuario, $documentoAnterior);

        if ($cuenta === null) {
            return $this->crearCuenta($usuario, $deseados ?? $this->gruposDeseados($usuario, $grupos), $clave);
        }

        if ($deseados === null) {
            $finales = $this->rolesDeLosGrupos($cuenta['groups'] ?? [], $grupos);
        } else {
            $finales = $this->ponerGrupos($usuario, $cuenta, $deseados, $grupos, quitarLosDemas: $roles === self::ROLES_EXACTOS);

            if ($roles === self::ROLES_AGREGAR) {
                $this->recordarRoles($usuario, $finales);
            }
        }

        // Ya tenía cuenta: la contraseña escrita solo se le pone si esa cuenta
        // la creó SISPAM. Una que ya existía puede usarla en otras aplicaciones.
        $claveNueva = null;

        if ($clave !== null) {
            $claveNueva = self::laCreoSispam($cuenta);

            if ($claveNueva) {
                $this->api->fijarClave((int) $cuenta['pk'], $clave);
                $this->auditar($usuario, 'Le cambió la contraseña en Authentik');
            }
        }

        return ResultadoAprovisionamiento::vinculada($finales, $claveNueva);
    }

    /**
     * Le cambia la contraseña: la que escribe el administrador.
     *
     * Solo para cuentas que creó SISPAM: una que ya existía puede ser de
     * alguien que la usa en otras aplicaciones, y esa se maneja en Authentik.
     * Si todavía no tiene cuenta, se le crea con esta contraseña.
     */
    public function cambiarClave(User $usuario, #[\SensitiveParameter] string $clave): ResultadoAprovisionamiento
    {
        if (! $this->api->configurada()) {
            throw new ErrorDeAuthentik('Falta configurar AUTHENTIK_API_TOKEN en el .env.');
        }

        $cuenta = $this->api->buscarUsuario($usuario->documento);

        if ($cuenta === null) {
            $grupos = $this->api->grupos();

            return $this->crearCuenta($usuario, $this->gruposDeseados($usuario, $grupos), $clave);
        }

        if (! self::laCreoSispam($cuenta)) {
            throw new ErrorDeAuthentik("La cuenta «{$usuario->documento}» no la creó SISPAM: su contraseña se cambia en Authentik.");
        }

        $this->api->fijarClave((int) $cuenta['pk'], $clave);
        $this->auditar($usuario, 'Le cambió la contraseña en Authentik');

        return ResultadoAprovisionamiento::claveCambiada();
    }

    /**
     * @param  array<string, mixed>  $cuenta
     */
    private static function laCreoSispam(array $cuenta): bool
    {
        return ($cuenta['attributes']['creado_desde'] ?? null) === self::ORIGEN;
    }

    /**
     * Los grupos que le tocan: sus roles de SISPAM, por pk del grupo.
     * Crea en Authentik el grupo de un rol que todavía no exista.
     *
     * @param  array<string, array{pk: string, is_superuser: bool}>  $grupos  todos los de Authentik; se le agregan los que se creen
     * @return array<string, string> pk del grupo => nombre del rol
     */
    private function gruposDeseados(User $usuario, array &$grupos): array
    {
        $deseados = [];

        foreach (self::rolesDeSispam($usuario->roles) as $rol) {
            if (! isset($grupos[$rol])) {
                $nuevo = $this->api->crearGrupo($rol);
                $grupos[$rol] = ['pk' => (string) $nuevo['pk'], 'is_superuser' => false];
            }

            if ($grupos[$rol]['is_superuser']) {
                throw new ErrorDeAuthentik("El grupo «{$rol}» de Authentik da superusuario: SISPAM no lo asigna. Quítale esa opción al grupo en Authentik o usa otro rol.");
            }

            $deseados[$grupos[$rol]['pk']] = $rol;
        }

        return $deseados;
    }

    /**
     * Solo los roles que existen en SISPAM. Lo demás que traiga `users.roles`
     * son grupos de otras aplicaciones, copiados de Authentik al ingresar.
     *
     * @param  array<int, string>|null  $roles
     * @return list<string>
     */
    public static function rolesDeSispam(?array $roles): array
    {
        $existentes = Rol::query()->pluck('nombre')->all();

        return array_values(array_unique(array_intersect($roles ?? [], $existentes)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buscarCuenta(User $usuario, ?string $documentoAnterior): ?array
    {
        $cuenta = $this->api->buscarUsuario($usuario->documento);

        if ($cuenta !== null || blank($documentoAnterior) || $documentoAnterior === $usuario->documento) {
            return $cuenta;
        }

        $anterior = $this->api->buscarUsuario($documentoAnterior);

        if ($anterior === null) {
            return null;
        }

        $cuenta = $this->api->actualizarUsuario((int) $anterior['pk'], ['username' => $usuario->documento]);
        $this->auditar($usuario, "Le cambió el usuario en Authentik: {$documentoAnterior} → {$usuario->documento}");

        return $cuenta;
    }

    /**
     * @param  array<string, string>  $deseados
     */
    private function crearCuenta(User $usuario, array $deseados, #[\SensitiveParameter] ?string $clave): ResultadoAprovisionamiento
    {
        $cuenta = $this->api->crearUsuario(array_filter([
            'username' => $usuario->documento,
            'name' => $usuario->nombre_completo,
            'email' => $usuario->email,
            'is_active' => true,
            'type' => 'internal',
            'path' => 'users',
            'groups' => array_keys($deseados),
            'attributes' => ['creado_desde' => self::ORIGEN],
        ], fn (mixed $valor): bool => $valor !== null));

        $roles = array_values($deseados);
        $this->auditar($usuario, 'Le creó la cuenta en Authentik'.($roles === [] ? ', sin roles' : ' con los roles '.implode(', ', $roles)));

        if ($clave === null) {
            return ResultadoAprovisionamiento::creadaSinClave(null, $roles);
        }

        // Si falla aquí, la cuenta ya existe allá: el usuario se guarda igual en
        // SISPAM para poder ponerle la contraseña con «Cambiar contraseña».
        try {
            $this->api->fijarClave((int) $cuenta['pk'], $clave);
        } catch (ErrorDeAuthentik $e) {
            return ResultadoAprovisionamiento::creadaSinClave($e->getMessage(), $roles);
        }

        return ResultadoAprovisionamiento::creada($roles);
    }

    /**
     * Le pone los grupos de sus roles de SISPAM, sin tocar los de otras
     * aplicaciones. Con `$quitarLosDemas`, le quita los de SISPAM que no se
     * eligieron; sin él, solo suma.
     *
     * @param  array<string, mixed>  $cuenta
     * @param  array<string, string>  $deseados
     * @param  array<string, array{pk: string, is_superuser: bool}>  $grupos
     * @return list<string> los roles de SISPAM con los que quedó
     */
    private function ponerGrupos(User $usuario, array $cuenta, array $deseados, array $grupos, bool $quitarLosDemas): array
    {
        $deSispam = collect($grupos)
            ->only(Rol::query()->pluck('nombre')->all())
            ->pluck('pk')
            ->all();

        $actuales = array_map('strval', $cuenta['groups'] ?? []);
        $base = $quitarLosDemas ? array_diff($actuales, $deSispam) : $actuales;
        $nuevos = array_values(array_unique([...$base, ...array_map('strval', array_keys($deseados))]));
        $finales = $this->rolesDeLosGrupos($nuevos, $grupos);

        if (collect($nuevos)->sort()->values()->all() !== collect($actuales)->sort()->values()->all()) {
            $this->api->actualizarUsuario((int) $cuenta['pk'], ['groups' => $nuevos]);
            $this->auditar($usuario, 'Le cambió los roles en Authentik; quedaron: '.(implode(', ', $finales) ?: 'ninguno'));
        }

        return $finales;
    }

    /**
     * Los roles de SISPAM que corresponden a esos grupos de Authentik.
     *
     * @param  array<int, string>  $pks
     * @param  array<string, array{pk: string, is_superuser: bool}>  $grupos
     * @return list<string>
     */
    private function rolesDeLosGrupos(array $pks, array $grupos): array
    {
        $nombres = collect($grupos)->mapWithKeys(fn (array $grupo, string $nombre): array => [$grupo['pk'] => $nombre]);

        return self::rolesDeSispam(collect($pks)
            ->map(fn (mixed $pk): ?string => $nombres[(string) $pk] ?? null)
            ->filter()
            ->sort()
            ->values()
            ->all());
    }

    /**
     * Si ya tenía cuenta al crearlo, sus roles de allá se suman a los
     * elegidos: así la lista de Usuarios los muestra sin esperar a que entre.
     *
     * @param  list<string>  $roles
     */
    private function recordarRoles(User $usuario, array $roles): void
    {
        $todos = array_values(array_unique([...((array) $usuario->roles), ...$roles]));

        if ($todos !== (array) $usuario->roles) {
            // Sin eventos: la línea «Le creó / le cambió…» ya queda en la auditoría.
            $usuario->forceFill(['roles' => $todos])->saveQuietly();
        }
    }

    private function auditar(User $usuario, string $descripcion): void
    {
        Auditoria::registrar(
            accion: Auditoria::ACCION_CUENTA_AUTHENTIK,
            descripcion: "{$descripcion} ({$usuario->documento})",
            entidadTipo: 'usuario',
            entidadId: $usuario->getKey(),
        );
    }
}
