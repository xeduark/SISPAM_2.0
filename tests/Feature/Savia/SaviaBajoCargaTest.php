<?php

namespace Tests\Feature\Savia;

use App\Models\User;
use App\Services\Savia\RespuestaConsulta;
use App\Services\Savia\SaviaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Lo que hace el cliente cuando varias personas consultan a la vez y cuando
 * Savia se cae. Las consultas simultáneas de verdad, con procesos aparte, están
 * en `ConsultasSimultaneasTest`; aquí se prueba cada regla por separado.
 */
class SaviaBajoCargaTest extends TestCase
{
    private const URL_TOKEN = '*rest/token/generacion';

    private const URL_CONSULTA = '*rest/afiliado/consultar-afiliado';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'savia.username' => '900000000',
            'savia.password' => 'clave-de-prueba',
            'savia.token' => null,
            'savia.auditar' => false,
        ]);
    }

    private function consultar(): RespuestaConsulta
    {
        return SaviaClient::desdeConfig()->consultarAfiliado(['tipoDocumento' => 'CC', 'numeroDocumento' => '999999999']);
    }

    private function afiliado(): array
    {
        return ['registros' => 1, 'codigo' => 0, 'mensaje' => 'ok', 'afiliados' => [['documentoAfiliado' => '999999999']]];
    }

    /** Consultas que salieron hacia Savia. Las que lanzan excepción no quedan en `Http::recorded()`. */
    private int $enviadas = 0;

    private function saviaCaida(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => function () {
                $this->enviadas++;

                throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds');
            },
        ]);
    }

    private function consultasEnviadas(): int
    {
        return $this->enviadas;
    }

    /* ------------------------------------------------------------------ *
     *  Un solo token a la vez
     * ------------------------------------------------------------------ */

    public function test_tras_un_401_usa_el_token_que_otra_consulta_ya_renovo(): void
    {
        Cache::put(SaviaClient::CLAVE_CACHE_TOKEN, 'TOKEN-VIEJO', 3600);

        $intentos = 0;
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'NO-DEBERIA-PEDIRSE']),
            self::URL_CONSULTA => function () use (&$intentos) {
                // Mientras esta consulta esperaba, otra renovó el token.
                if (++$intentos === 1) {
                    Cache::put(SaviaClient::CLAVE_CACHE_TOKEN, 'TOKEN-NUEVO', 3600);

                    return Http::response(['codigo' => -1, 'mensaje' => 'Token inválido'], 401);
                }

                return Http::response($this->afiliado());
            },
        ]);

        $this->assertTrue($this->consultar()->exitosa());

        Http::assertNotSent(fn (Request $peticion): bool => str_contains($peticion->url(), 'token/generacion'));
        Http::assertSent(fn (Request $peticion): bool => $peticion->hasHeader('Authorization', 'Bearer TOKEN-NUEVO'));
    }

    public function test_si_otra_consulta_tiene_el_candado_demasiado_tiempo_responde_en_vez_de_colgarse(): void
    {
        config(['savia.espera_token' => 1]);
        Http::fake();

        // Otra consulta está pidiendo el token y no lo suelta.
        Cache::lock(SaviaClient::CANDADO_TOKEN, 30)->get();

        $respuesta = $this->consultar();

        $this->assertStringContainsString('Savia está tardando en entregar el token', (string) $respuesta->errorToken);
        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------ *
     *  Pausa cuando Savia está caída
     * ------------------------------------------------------------------ */

    public function test_tras_tres_fallas_seguidas_deja_de_llamar_a_savia_por_30_segundos(): void
    {
        $this->saviaCaida();

        $this->consultar();
        $this->consultar();
        $this->consultar();
        $this->assertSame(3, $this->consultasEnviadas());

        $pausada = $this->consultar();
        $this->assertSame(3, $this->consultasEnviadas(), 'Durante la pausa no debe salir ninguna petición.');
        $this->assertStringContainsString('se pausaron las consultas', (string) $pausada->errorRed);
        $this->assertNotNull(SaviaClient::desdeConfig()->pausadoHasta());

        // Pasada la pausa se vuelve a intentar, y si falla se pausa de una vez.
        $this->travel(31)->seconds();
        $this->consultar();
        $this->assertSame(4, $this->consultasEnviadas());
        $this->consultar();
        $this->assertSame(4, $this->consultasEnviadas());
    }

    public function test_una_respuesta_de_savia_reinicia_la_cuenta_de_fallas(): void
    {
        $fallar = true;
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => function () use (&$fallar) {
                $this->enviadas++;

                if ($fallar) {
                    throw new ConnectionException('cURL error 7: Failed to connect');
                }

                return Http::response(['codigo' => -1000, 'mensaje' => 'Afiliado no encontrado'], 200);
            },
        ]);

        $this->consultar();
        $this->consultar();
        $fallar = false;
        $this->consultar(); // «no encontrado» también es Savia respondiendo
        $fallar = true;
        $this->consultar();
        $this->consultar();

        $this->assertNull(SaviaClient::desdeConfig()->pausadoHasta());
        $this->assertSame(5, $this->consultasEnviadas());
    }

    public function test_las_fallas_quedan_en_el_log_sin_el_documento_consultado(): void
    {
        $this->saviaCaida();
        Log::spy();

        $this->consultar();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mensaje, array $contexto): bool => $mensaje === 'Savia no respondió bien'
                && str_contains((string) $contexto['error'], 'timed out')
                && ! str_contains(json_encode($contexto), '999999999'))
            ->once();
    }

    /* ------------------------------------------------------------------ *
     *  Lo que ve cada quien
     * ------------------------------------------------------------------ */

    public function test_quien_no_administra_no_ve_el_detalle_tecnico(): void
    {
        $this->saviaCaida();
        $respuesta = $this->consultar();

        $paraConsulta = $respuesta->diagnosticoPara(new User(['es_administrador' => false]));
        $this->assertSame('Savia no respondió', $paraConsulta['titulo']);
        $this->assertStringNotContainsString('cURL', $paraConsulta['detalle']);
        $this->assertStringNotContainsString('.env', $paraConsulta['detalle']);

        $paraAdministrador = $respuesta->diagnosticoPara(new User(['es_administrador' => true]));
        $this->assertStringContainsString('cURL error 28', $paraAdministrador['detalle']);
    }

    /**
     * @testWith [200]
     *           [404]
     */
    public function test_el_no_encontrado_si_se_le_muestra_a_quien_consulta(int $http): void
    {
        // Savia lo manda unas veces con 200 y otras con 404.
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => Http::response(['codigo' => -1000, 'mensaje' => 'Afiliado no encontrado'], $http),
        ]);

        $diagnostico = $this->consultar()->diagnosticoPara(new User(['es_administrador' => false]));

        $this->assertSame('Afiliado no encontrado', $diagnostico['titulo']);
    }
}
