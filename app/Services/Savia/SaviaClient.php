<?php

namespace App\Services\Savia;

use Illuminate\Contracts\Cache\LockTimeoutException;
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
 *
 * ## Varias personas consultando a la vez
 *
 * - **Un solo token a la vez.** Renovarlo pasa por un candado en la caché
 *   (`CANDADO_TOKEN`): una petición lo pide y las demás esperan y usan ese.
 *   Si Savia anula el token anterior al emitir uno nuevo —muchos servicios lo
 *   hacen—, varias renovaciones a la vez se anularían el token unas a otras.
 * - **No se renueva lo que otro ya renovó.** Tras un 401 se compara el token
 *   que falló con el de la caché: si ya es otro, se usa ese.
 * - **Pausa cuando Savia está caída.** Tras `FALLAS_PARA_PAUSAR` fallas de red
 *   seguidas (tiempo agotado, conexión rechazada, HTTP 5xx), las consultas
 *   responden de inmediato durante `SEGUNDOS_DE_PAUSA` en vez de ocupar cada
 *   una un proceso de PHP hasta el tiempo límite. Si la primera después de la
 *   pausa vuelve a fallar, se pausa otra vez.
 *
 * La caché tiene que admitir candados: `database` (la de producción), `file`
 * y `redis` los admiten.
 *
 * Ver `ConsultasSimultaneasTest`, que lo prueba con procesos de verdad.
 */
class SaviaClient
{
    public const CLAVE_CACHE_TOKEN = 'savia:token';

    public const CANDADO_TOKEN = 'savia:token:renovar';

    public const CLAVE_FALLAS = 'savia:fallas_seguidas';

    public const CLAVE_PAUSA = 'savia:pausa_hasta';

    public const FALLAS_PARA_PAUSAR = 3;

    public const SEGUNDOS_DE_PAUSA = 30;

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
     * Token listo para usar: [token, origen] o [null, motivo del fallo, si fue de red].
     *
     * `$rechazado` es el token al que Savia acaba de responder 401. Si en la
     * caché ya hay otro, otra consulta lo renovó mientras tanto y se usa ese.
     *
     * @return array{0: ?string, 1: string, 2?: bool}
     */
    public function token(?string $rechazado = null): array
    {
        if ($this->hayCredenciales()) {
            $cacheado = Cache::get(self::CLAVE_CACHE_TOKEN);

            if ($cacheado !== null && $cacheado !== $rechazado) {
                return [$cacheado, $rechazado === null ? 'caché' : 'renovado por otra consulta'];
            }

            return $this->renovarToken($rechazado);
        }

        if ($this->hayTokenFijo()) {
            return [$this->cfg['token'], 'config'];
        }

        return [null, 'No hay credenciales configuradas. Revisa SAVIA_USERNAME y SAVIA_PASSWORD en el .env.'];
    }

    /**
     * Pide un token nuevo, una petición a la vez.
     *
     * Las demás esperan el candado y, al entrar, encuentran el token que dejó
     * la primera. Si quien lo tiene se cae, el candado se suelta solo
     * (`segundosDelCandado()`); quien espere más de `segundosDeEspera()` recibe
     * un error en vez de quedarse colgado.
     *
     * @return array{0: ?string, 1: string, 2?: bool}
     */
    private function renovarToken(?string $rechazado): array
    {
        try {
            return Cache::lock(self::CANDADO_TOKEN, $this->segundosDelCandado())
                ->block($this->segundosDeEspera(), function () use ($rechazado): array {
                    $cacheado = Cache::get(self::CLAVE_CACHE_TOKEN);

                    if ($cacheado !== null && $cacheado !== $rechazado) {
                        return [$cacheado, 'renovado por otra consulta'];
                    }

                    // Savia se acaba de dar por caída mientras se esperaba el candado.
                    if ($this->pausadoHasta() !== null) {
                        return [null, $this->motivoDeLaPausa(), false];
                    }

                    [$token, $segundos, $error, $deRed] = $this->generarToken();

                    if ($token === null) {
                        return [null, $error, $deRed];
                    }

                    Cache::put(self::CLAVE_CACHE_TOKEN, $token, now()->addSeconds($segundos));

                    return [$token, 'generado'];
                });
        } catch (LockTimeoutException) {
            return [null, 'Savia está tardando en entregar el token: otra consulta lo está pidiendo hace más de '
                .$this->segundosDeEspera().' s.', true];
        }
    }

    /**
     * Pide un token nuevo. POST x-www-form-urlencoded, como exige el servicio.
     *
     * @return array{0: ?string, 1: int, 2: ?string, 3: bool} [token, segundos de vida, error, si el error fue de red]
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
            return [null, 0, 'No se pudo contactar el endpoint de token: '.$e->getMessage(), true];
        }

        $token = $this->extraerToken($respuesta->json(), $respuesta->body());

        if ($token === null) {
            $extracto = trim(mb_substr(strip_tags($respuesta->body()), 0, 300));

            return [null, 0, ($respuesta->successful()
                ? 'El servicio respondió '.$respuesta->status().' pero no se encontró el token en el cuerpo.'
                : 'El endpoint de token respondió HTTP '.$respuesta->status().'.')
                .($extracto !== '' ? ' Respondió: '.$extracto : ''), $respuesta->serverError(), ];
        }

        $segundos = $segundosPorDefecto;
        foreach (['expires_in', 'expiresIn'] as $clave) {
            $valor = data_get($respuesta->json(), $clave);
            if (is_numeric($valor) && $valor > 0) {
                $segundos = (int) $valor;
                break;
            }
        }

        return [$token, $segundos, null, false];
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

        // Savia está caída: se responde ya, sin ocupar el proceso hasta el límite.
        if ($this->pausadoHasta() !== null) {
            return new RespuestaConsulta(
                httpCode: 0,
                json: null,
                crudo: '',
                enviado: $payload,
                duracionMs: 0,
                errorRed: $this->motivoDeLaPausa(),
            );
        }

        [$token, $origen, $falloDeRed] = $this->token() + [2 => false];

        if ($token === null) {
            $respuesta = new RespuestaConsulta(
                httpCode: 0,
                json: null,
                crudo: '',
                enviado: $payload,
                duracionMs: 0,
                errorToken: $origen,
            );

            $this->anotarResultado($respuesta, $falloDeRed);

            return $respuesta;
        }

        $respuesta = $this->enviar($payload, $token, $origen);

        if ($respuesta->httpCode === 401 && $this->hayCredenciales()) {
            [$nuevo, $origenNuevo, $falloDeRed] = $this->token(rechazado: $token) + [2 => false];
            if ($nuevo !== null) {
                $respuesta = $this->enviar($payload, $nuevo, $origenNuevo === 'generado' ? 'renovado tras 401' : $origenNuevo);
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

        $this->anotarResultado($respuesta, $falloDeRed || $respuesta->esFallaDeRed());
        $this->auditar($payload, $respuesta);

        return $respuesta;
    }

    /* ------------------------------------------------------------------ *
     *  Pausa cuando Savia está caída
     * ------------------------------------------------------------------ */

    /** Hasta cuándo están en pausa las consultas (segundos Unix), o null si no lo están. */
    public function pausadoHasta(): ?int
    {
        $hasta = Cache::get(self::CLAVE_PAUSA);

        return is_int($hasta) && $hasta > now()->getTimestamp() ? $hasta : null;
    }

    private function motivoDeLaPausa(): string
    {
        return 'Savia no está respondiendo: tras '.self::FALLAS_PARA_PAUSAR.' fallas seguidas se pausaron las consultas '
            .self::SEGUNDOS_DE_PAUSA.' s para no ocupar el servidor. Se vuelve a intentar a las '
            .date('H:i:s', $this->pausadoHasta() ?? now()->getTimestamp()).'.';
    }

    /**
     * Cuenta las fallas de red seguidas y, al llegar a `FALLAS_PARA_PAUSAR`,
     * pausa las consultas. Cualquier respuesta de Savia, aunque sea un error
     * suyo como «no encontrado», reinicia la cuenta: Savia está ahí.
     *
     * Las fallas técnicas quedan en el log como advertencia, sin el documento
     * consultado, aunque `SAVIA_AUDITAR` esté apagado.
     */
    private function anotarResultado(RespuestaConsulta $respuesta, bool $falloDeRed): void
    {
        if ($respuesta->esFallaTecnica()) {
            Log::warning('Savia no respondió bien', [
                'usuario' => auth()->id(),
                'http' => $respuesta->httpCode,
                'codigo' => $respuesta->codigo(),
                'error' => mb_substr((string) ($respuesta->errorRed ?? $respuesta->errorToken), 0, 300) ?: null,
                'ms' => $respuesta->duracionMs,
            ]);
        }

        if (! $falloDeRed) {
            Cache::forget(self::CLAVE_FALLAS);

            return;
        }

        // La cuenta vive un minuto desde la primera falla.
        Cache::add(self::CLAVE_FALLAS, 0, now()->addMinute());

        if ((int) Cache::increment(self::CLAVE_FALLAS) < self::FALLAS_PARA_PAUSAR) {
            return;
        }

        Cache::put(self::CLAVE_PAUSA, now()->addSeconds(self::SEGUNDOS_DE_PAUSA)->getTimestamp(), now()->addSeconds(self::SEGUNDOS_DE_PAUSA));

        // Tras la pausa basta una falla más para volver a pausar: la primera
        // consulta es la que prueba si Savia volvió.
        Cache::put(self::CLAVE_FALLAS, self::FALLAS_PARA_PAUSAR - 1, now()->addSeconds(self::SEGUNDOS_DE_PAUSA + 60));

        Log::warning('Savia: se pausan las consultas', [
            'fallas_seguidas' => self::FALLAS_PARA_PAUSAR,
            'segundos' => self::SEGUNDOS_DE_PAUSA,
        ]);
    }

    /* ------------------------------------------------------------------ *
     *  Tiempos
     * ------------------------------------------------------------------ */

    private function segundosLimite(): int
    {
        return max(1, (int) ($this->cfg['timeout'] ?? 10));
    }

    /** Lo que espera una consulta mientras otra pide el token. */
    private function segundosDeEspera(): int
    {
        return max(1, (int) ($this->cfg['espera_token'] ?? $this->segundosLimite() + 2));
    }

    /** Lo que dura el candado si quien lo tiene se cae: más que pedir un token. */
    private function segundosDelCandado(): int
    {
        return $this->segundosLimite() + 5;
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
        $peticion = Http::timeout($this->segundosLimite())
            ->connectTimeout(max(1, (int) ($this->cfg['connect_timeout'] ?? 5)));

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
