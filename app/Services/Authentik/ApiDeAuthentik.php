<?php

namespace App\Services\Authentik;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Lo poco de la API de Authentik (v3) que SISPAM usa para crear cuentas.
 *
 * Entra con el token de una cuenta de servicio (`AUTHENTIK_API_TOKEN`) que
 * solo puede ver, crear y editar usuarios, ponerles contraseña, y ver y crear
 * grupos. Cómo crearla: docs/despliegue.md. Probado contra Authentik 2024.8.
 */
class ApiDeAuthentik
{
    public function __construct(
        private readonly ?string $urlBase,
        private readonly ?string $token,
    ) {}

    public static function desdeConfig(): self
    {
        return new self(
            config('services.authentik.base_url'),
            config('services.authentik.api_token'),
        );
    }

    public function configurada(): bool
    {
        return filled($this->urlBase) && filled($this->token);
    }

    /**
     * La cuenta con ese username exacto, o null si no existe.
     *
     * @return array<string, mixed>|null
     */
    public function buscarUsuario(string $username): ?array
    {
        $resultados = $this->enviar('GET', 'core/users/', [
            'username' => $username,
            'include_groups' => 'false',
        ])['results'] ?? [];

        return collect($resultados)->first(fn (array $cuenta): bool => ($cuenta['username'] ?? null) === $username);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function crearUsuario(array $datos): array
    {
        return $this->enviar('POST', 'core/users/', $datos);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function actualizarUsuario(int $pk, array $datos): array
    {
        return $this->enviar('PATCH', "core/users/{$pk}/", $datos);
    }

    public function fijarClave(int $pk, #[\SensitiveParameter] string $clave): void
    {
        $this->enviar('POST', "core/users/{$pk}/set_password/", ['password' => $clave]);
    }

    /**
     * Todos los grupos, por nombre (en Authentik el nombre es único).
     *
     * @return array<string, array{pk: string, is_superuser: bool}>
     */
    public function grupos(): array
    {
        $resultados = $this->enviar('GET', 'core/groups/', [
            'page_size' => 1000,
            'include_users' => 'false',
        ])['results'] ?? [];

        return collect($resultados)
            ->mapWithKeys(fn (array $grupo): array => [$grupo['name'] => [
                'pk' => (string) $grupo['pk'],
                'is_superuser' => (bool) ($grupo['is_superuser'] ?? false),
            ]])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function crearGrupo(string $nombre): array
    {
        return $this->enviar('POST', 'core/groups/', ['name' => $nombre, 'is_superuser' => false]);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function enviar(string $metodo, string $ruta, array $datos = []): array
    {
        if (! $this->configurada()) {
            throw new ErrorDeAuthentik('Falta configurar AUTHENTIK_BASE_URL o AUTHENTIK_API_TOKEN en el .env.');
        }

        try {
            $respuesta = $this->cliente()->send($metodo, $ruta, $metodo === 'GET' ? ['query' => $datos] : ['json' => $datos]);
        } catch (ConnectionException $e) {
            throw new ErrorDeAuthentik('Authentik no respondió. Intenta de nuevo en un momento.', previous: $e);
        }

        if ($respuesta->failed()) {
            throw ErrorDeAuthentik::desdeRespuesta($respuesta);
        }

        // Poner la contraseña responde 204, sin cuerpo.
        return $respuesta->json() ?? [];
    }

    private function cliente(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->urlBase, '/').'/api/v3/')
            ->withToken((string) $this->token)
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->connectTimeout(5);
    }
}
