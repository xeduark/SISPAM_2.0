<?php

namespace App\Services\Savia;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente del servicio Consulta Afiliados de Savia Salud EPS.
 *
 * Savia no entrega un token fijo: entrega credenciales y un endpoint de
 * generación. Esta clase pide el token, lo guarda en caché mientras esté
 * vigente, y lo renueva sola cuando el servicio responde 401.
 */
class SaviaClient
{
    public const CLAVE_CACHE_TOKEN = 'savia:token';

    public function __construct(private readonly array $cfg) {}

    public static function desdeConfig(): self
    {
        return new self(config('savia'));
    }

    /* ------------------------------------------------------------------ *
     *  Token
     * ------------------------------------------------------------------ */

    public function hayCredenciales(): bool
    {
        return filled($this->cfg['username'] ?? null) && filled($this->cfg['password'] ?? null);
    }

    public function hayTokenFijo(): bool
    {
        return filled($this->cfg['token'] ?? null);
    }

    public function configurado(): bool
    {
        return $this->hayCredenciales() || $this->hayTokenFijo();
    }

    /**
     * Token listo para usar. Devuelve [token, origen] u [null, motivo del fallo].
     *
     * @return array{0: ?string, 1: string}
     */
    public function token(bool $forzar = false): array
    {
        if ($this->hayCredenciales()) {
            if ($forzar) {
                Cache::forget(self::CLAVE_CACHE_TOKEN);
            } elseif (($cacheado = Cache::get(self::CLAVE_CACHE_TOKEN)) !== null) {
                return [$cacheado, 'caché'];
            }

            [$token, $segundos, $error] = $this->generarToken();

            if ($token === null) {
                return [null, $error];
            }

            Cache::put(self::CLAVE_CACHE_TOKEN, $token, now()->addSeconds($segundos));

            return [$token, 'generado'];
        }

        if ($this->hayTokenFijo()) {
            return [$this->cfg['token'], 'config'];
        }

        return [null, 'No hay credenciales configuradas. Revisa SAVIA_USERNAME y SAVIA_PASSWORD en el .env.'];
    }

    /**
     * Pide un token nuevo. POST x-www-form-urlencoded, como exige el servicio.
     *
     * @return array{0: ?string, 1: int, 2: ?string} [token, segundos de vida, error]
     */
    public function generarToken(): array
    {
        $segundosPorDefecto = max(60, (int) ($this->cfg['token_minutos'] ?? 55) * 60);

        try {
            $respuesta = $this->http()
                ->asForm()
                ->post($this->cfg['endpoint_token'], [
                    'username' => (string) $this->cfg['username'],
                    'password' => (string) $this->cfg['password'],
                    'grant_type' => (string) ($this->cfg['grant_type'] ?? ''),
                ]);
        } catch (\Throwable $e) {
            return [null, 0, 'No se pudo contactar el endpoint de token: '.$e->getMessage()];
        }

        $token = $this->extraerToken($respuesta->json(), $respuesta->body());

        if ($token === null) {
            $extracto = trim(mb_substr(strip_tags($respuesta->body()), 0, 300));

            return [null, 0, ($respuesta->successful()
                ? 'El servicio respondió '.$respuesta->status().' pero no se encontró el token en el cuerpo.'
                : 'El endpoint de token respondió HTTP '.$respuesta->status().'.')
                .($extracto !== '' ? ' Respondió: '.$extracto : ''), ];
        }

        $segundos = $segundosPorDefecto;
        foreach (['expires_in', 'expiresIn'] as $clave) {
            $valor = data_get($respuesta->json(), $clave);
            if (is_numeric($valor) && $valor > 0) {
                $segundos = (int) $valor;
                break;
            }
        }

        return [$token, $segundos, null];
    }

    /**
     * Busca el token en la respuesta sin casarse con un nombre de campo:
     * el servicio podría devolverlo anidado o incluso en texto plano.
     */
    private function extraerToken(mixed $json, string $cuerpo): ?string
    {
        if (is_array($json)) {
            foreach (['access_token', 'accessToken', 'token', 'Token'] as $clave) {
                if (! empty($json[$clave]) && is_string($json[$clave])) {
                    return trim($json[$clave]);
                }
            }
            foreach ($json as $valor) {
                if (is_array($valor)) {
                    foreach (['access_token', 'accessToken', 'token'] as $clave) {
                        if (! empty($valor[$clave]) && is_string($valor[$clave])) {
                            return trim($valor[$clave]);
                        }
                    }
                }
            }

            return null;
        }

        $plano = trim($cuerpo);

        return preg_match('/^[A-Za-z0-9.\-_ ]{20,}$/', $plano) ? $plano : null;
    }

    /* ------------------------------------------------------------------ *
     *  Consulta
     * ------------------------------------------------------------------ */

    /**
     * Consulta un afiliado. Si el servicio responde 401, renueva el token
     * y reintenta una sola vez antes de devolver el error.
     */
    public function consultarAfiliado(array $datos): RespuestaConsulta
    {
        $payload = $this->construirPayload($datos);

        [$token, $origen] = $this->token();

        if ($token === null) {
            return new RespuestaConsulta(
                httpCode: 0,
                json: null,
                crudo: '',
                enviado: $payload,
                duracionMs: 0,
                errorToken: $origen,
            );
        }

        $respuesta = $this->enviar($payload, $token, $origen);

        if ($respuesta->httpCode === 401 && $this->hayCredenciales()) {
            [$nuevo, $origenNuevo] = $this->token(forzar: true);
            if ($nuevo !== null) {
                $respuesta = $this->enviar($payload, $nuevo, 'renovado tras 401');
            } else {
                $respuesta = new RespuestaConsulta(
                    httpCode: 401,
                    json: $respuesta->json,
                    crudo: $respuesta->crudo,
                    enviado: $payload,
                    duracionMs: $respuesta->duracionMs,
                    errorToken: $origenNuevo,
                );
            }
        }

        $this->auditar($payload, $respuesta);

        return $respuesta;
    }

    private function enviar(array $payload, string $token, string $origenToken): RespuestaConsulta
    {
        $inicio = microtime(true);

        try {
            $respuesta = $this->http()
                ->withToken($token)
                ->acceptJson()
                ->post($this->cfg['endpoint'], $payload);
        } catch (\Throwable $e) {
            return new RespuestaConsulta(
                httpCode: 0,
                json: null,
                crudo: '',
                enviado: $payload,
                duracionMs: (int) round((microtime(true) - $inicio) * 1000),
                origenToken: $origenToken,
                errorRed: $e->getMessage(),
            );
        }

        return new RespuestaConsulta(
            httpCode: $respuesta->status(),
            json: is_array($respuesta->json()) ? $respuesta->json() : null,
            crudo: $respuesta->body(),
            enviado: $payload,
            duracionMs: (int) round((microtime(true) - $inicio) * 1000),
            origenToken: $origenToken,
        );
    }

    /**
     * Los siete atributos siempre presentes, los vacíos como cadena vacía,
     * en el orden del documento.
     */
    public function construirPayload(array $datos): array
    {
        $payload = [];
        foreach ($this->cfg['campos_peticion'] as $campo) {
            $payload[$campo] = trim((string) ($datos[$campo] ?? ''));
        }
        $payload['tipoDocumento'] = strtoupper($payload['tipoDocumento']);

        return $payload;
    }

    private function http(): PendingRequest
    {
        $peticion = Http::timeout((int) ($this->cfg['timeout'] ?? 30))
            ->connectTimeout(15);

        if (! ($this->cfg['verify_ssl'] ?? false)) {
            $peticion = $peticion->withoutVerifying();
        }

        return $peticion;
    }

    /**
     * Deja rastro de quién consultó qué. Se registra el documento consultado
     * y el resultado, nunca los datos del afiliado.
     */
    private function auditar(array $payload, RespuestaConsulta $respuesta): void
    {
        if (! ($this->cfg['auditar'] ?? true)) {
            return;
        }

        Log::channel(config('logging.default'))->info('Consulta afiliado Savia', [
            'usuario' => auth()->id(),
            'tipoDocumento' => $payload['tipoDocumento'],
            'numeroDocumento' => $payload['numeroDocumento'],
            'http' => $respuesta->httpCode,
            'codigo' => $respuesta->codigo(),
            'registros' => $respuesta->json ? $respuesta->registros() : 0,
            'ms' => $respuesta->duracionMs,
        ]);
    }
}
