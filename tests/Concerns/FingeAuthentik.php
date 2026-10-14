<?php

namespace Tests\Concerns;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Authentik de mentiras para las pruebas de Usuarios.
 *
 * Guarda en memoria las cuentas, los grupos y las contraseñas, y responde la
 * parte de la API v3 que usa SISPAM (`ApiDeAuthentik`), con las mismas formas
 * que Authentik 2024.8. Nunca toca un Authentik real.
 */
trait FingeAuthentik
{
    /** @var array<int, array<string, mixed>> pk => cuenta */
    protected array $cuentasAuthentik = [];

    /** @var array<string, array{pk: string, name: string, is_superuser: bool}> uuid => grupo */
    protected array $gruposAuthentik = [];

    /** @var array<int, string> pk => última contraseña que se le puso */
    protected array $clavesAuthentik = [];

    /**
     * Rutas que responden con error: «MÉTODO ruta» (con {pk}) => código HTTP.
     *
     * @var array<string, int>
     */
    protected array $fallosAuthentik = [];

    protected function configurarAuthentik(): void
    {
        config([
            'services.authentik.base_url' => 'https://auth.example.com',
            'services.authentik.api_token' => 'token-de-prueba',
        ]);

        Http::fake(fn (Request $peticion): PromiseInterface => $this->responderComoAuthentik($peticion));
    }

    protected function grupoEnAuthentik(string $nombre, bool $superusuario = false): string
    {
        $uuid = (string) Str::uuid();
        $this->gruposAuthentik[$uuid] = ['pk' => $uuid, 'name' => $nombre, 'is_superuser' => $superusuario];

        return $uuid;
    }

    /**
     * @param  list<string>  $grupos
     * @param  array<string, mixed>  $atributos
     */
    protected function cuentaEnAuthentik(string $username, array $grupos = [], array $atributos = []): int
    {
        $pk = max([0, ...array_keys($this->cuentasAuthentik)]) + 1;

        $this->cuentasAuthentik[$pk] = [
            'pk' => $pk,
            'username' => $username,
            'name' => $username,
            'email' => '',
            'is_active' => true,
            'type' => 'internal',
            'path' => 'users',
            'groups' => $grupos,
            'attributes' => $atributos,
        ];

        return $pk;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function cuentaAuthentik(string $username): ?array
    {
        return collect($this->cuentasAuthentik)->firstWhere('username', $username);
    }

    protected function uuidDelGrupo(string $nombre): ?string
    {
        return collect($this->gruposAuthentik)->firstWhere('name', $nombre)['pk'] ?? null;
    }

    private function responderComoAuthentik(Request $peticion): PromiseInterface
    {
        // Siempre con el token de la cuenta de servicio.
        $this->assertSame(['Bearer token-de-prueba'], $peticion->header('Authorization'));

        $ruta = Str::after((string) parse_url($peticion->url(), PHP_URL_PATH), '/api/v3/');
        $clave = $peticion->method().' '.preg_replace('/\d+/', '{pk}', $ruta);
        parse_str((string) parse_url($peticion->url(), PHP_URL_QUERY), $consulta);
        $datos = $peticion->data();
        $pk = (int) preg_replace('/\D+/', '', $ruta);

        if (isset($this->fallosAuthentik[$clave])) {
            return Http::response(['detail' => 'Falla de prueba.'], $this->fallosAuthentik[$clave]);
        }

        switch ($clave) {
            case 'GET core/groups/':
                return Http::response(['pagination' => ['count' => count($this->gruposAuthentik)], 'results' => array_values($this->gruposAuthentik)]);

            case 'POST core/groups/':
                if ($this->uuidDelGrupo($datos['name']) !== null) {
                    return Http::response(['name' => ['group with this name already exists.']], 400);
                }

                $uuid = $this->grupoEnAuthentik($datos['name'], (bool) ($datos['is_superuser'] ?? false));

                return Http::response($this->gruposAuthentik[$uuid], 201);

            case 'GET core/users/':
                $resultados = array_values(array_filter(
                    $this->cuentasAuthentik,
                    fn (array $cuenta): bool => $cuenta['username'] === ($consulta['username'] ?? null),
                ));

                return Http::response(['pagination' => ['count' => count($resultados)], 'results' => $resultados]);

            case 'POST core/users/':
                if ($this->cuentaAuthentik($datos['username']) !== null) {
                    return Http::response(['username' => ['This field must be unique.']], 400);
                }

                $nuevo = $this->cuentaEnAuthentik($datos['username'], $datos['groups'] ?? [], $datos['attributes'] ?? []);
                $this->cuentasAuthentik[$nuevo] = [...$this->cuentasAuthentik[$nuevo], ...$datos, 'pk' => $nuevo];

                return Http::response($this->cuentasAuthentik[$nuevo], 201);

            case 'PATCH core/users/{pk}/':
                if (! isset($this->cuentasAuthentik[$pk])) {
                    return Http::response(['detail' => 'Not found.'], 404);
                }

                $this->cuentasAuthentik[$pk] = [...$this->cuentasAuthentik[$pk], ...$datos];

                return Http::response($this->cuentasAuthentik[$pk]);

            case 'POST core/users/{pk}/set_password/':
                $this->clavesAuthentik[$pk] = $datos['password'];

                return Http::response(null, 204);
        }

        return Http::response(['detail' => 'Not found.'], 404);
    }
}
