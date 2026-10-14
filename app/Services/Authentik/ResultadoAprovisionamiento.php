<?php

namespace App\Services\Authentik;

use App\Models\User;
use Filament\Notifications\Notification;

/**
 * Qué pasó en Authentik al guardar a alguien, y el aviso para quien lo guardó.
 *
 * Nunca lleva la contraseña: la escribió el mismo administrador, así que no
 * hay nada que mostrarle, y un aviso pasa por la sesión.
 */
final class ResultadoAprovisionamiento
{
    /** Se le creó la cuenta con la contraseña que se escribió. */
    public const CREADA = 'creada';

    /** Se le creó la cuenta sin contraseña: no se escribió, o Authentik no la aceptó. */
    public const CREADA_SIN_CLAVE = 'creada_sin_clave';

    /** Ya tenía cuenta: se usó esa. */
    public const VINCULADA = 'vinculada';

    /** Se le cambió la contraseña. */
    public const CLAVE_CAMBIADA = 'clave_cambiada';

    /** No hay token de la API: el usuario quedó solo en SISPAM. */
    public const SIN_CONFIGURAR = 'sin_configurar';

    /**
     * @param  list<string>  $roles
     * @param  bool|null  $claveNueva  si ya tenía cuenta: true, se le puso la escrita; false, no (la cuenta no la creó SISPAM); null, no se escribió ninguna
     */
    private function __construct(
        public readonly string $tipo,
        public readonly array $roles = [],
        public readonly ?string $motivo = null,
        public readonly ?bool $claveNueva = null,
    ) {}

    /**
     * @param  list<string>  $roles
     */
    public static function creada(array $roles): self
    {
        return new self(self::CREADA, roles: $roles);
    }

    /**
     * @param  list<string>  $roles
     */
    public static function creadaSinClave(?string $motivo, array $roles): self
    {
        return new self(self::CREADA_SIN_CLAVE, roles: $roles, motivo: $motivo);
    }

    /**
     * @param  list<string>  $roles
     */
    public static function vinculada(array $roles, ?bool $claveNueva = null): self
    {
        return new self(self::VINCULADA, roles: $roles, claveNueva: $claveNueva);
    }

    public static function claveCambiada(): self
    {
        return new self(self::CLAVE_CAMBIADA);
    }

    public static function sinConfigurar(): self
    {
        return new self(self::SIN_CONFIGURAR);
    }

    public function notificacion(User $usuario): Notification
    {
        return match ($this->tipo) {
            self::CREADA => Notification::make()
                ->success()
                ->title("{$usuario->nombre_completo} ya puede entrar")
                ->body("Se le creó la cuenta en Authentik con el usuario {$usuario->documento} y la contraseña que escribiste. {$this->textoRoles($usuario)}"),
            self::CREADA_SIN_CLAVE => Notification::make()
                ->warning()
                ->persistent()
                ->title('Se creó su cuenta en Authentik, pero sin contraseña')
                ->body(($this->motivo !== null ? "{$this->motivo} " : '').'Pónsela con «Cambiar contraseña» en la lista de usuarios.'),
            self::VINCULADA => Notification::make()
                ->status($this->claveNueva === false ? 'warning' : 'success')
                ->persistent($this->claveNueva === false)
                ->title('Usuario guardado')
                ->body("Ya tenía cuenta en Authentik ({$usuario->documento}). ".match ($this->claveNueva) {
                    true => 'Ahora entra con la contraseña que escribiste. ',
                    false => 'Conserva su contraseña de siempre: la que escribiste no se usó, porque esa cuenta no la creó SISPAM y puede usarla en otras aplicaciones. ',
                    null => '',
                }.$this->textoRoles($usuario)),
            self::CLAVE_CAMBIADA => Notification::make()
                ->success()
                ->title('Contraseña cambiada')
                ->body("{$usuario->nombre_completo} ya entra con la contraseña que escribiste."),
            default => Notification::make()
                ->warning()
                ->persistent()
                ->title('Usuario guardado solo en SISPAM')
                ->body('No se creó en Authentik porque falta AUTHENTIK_API_TOKEN en el .env. Mientras no tenga cuenta allá, no puede entrar.'),
        };
    }

    private function textoRoles(User $usuario): string
    {
        return match (true) {
            $this->roles !== [] => 'Roles: '.implode(', ', $this->roles).'.',
            (bool) $usuario->es_administrador => 'Es administrador: ve todo en SISPAM.',
            default => 'Sin roles: puede entrar, pero no verá nada hasta que le asignes uno.',
        };
    }
}
