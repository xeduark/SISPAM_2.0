<?php

namespace Tests\Feature\Savia;

use App\Models\User;
use App\Services\Savia\RespuestaConsulta;
use App\Services\Savia\SaviaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * El cliente se prueba sin tocar el servicio real: Http::fake() responde
 * en lugar de Savia.
 */
class SaviaClientTest extends TestCase
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

    private function cliente(): SaviaClient
    {
        return SaviaClient::desdeConfig();
    }

    /** Respuesta de ejemplo con la estructura que devuelve el servicio. */
    private function afiliadoDeEjemplo(array $extra = []): array
    {
        return [
            'registros' => 1,
            'codigo' => 0,
            'mensaje' => 'Afiliado encontrado',
            'fechaTransaccion' => '29/09/2026 10:00:00',
            'afiliados' => [array_merge([
                'tipoDocumentoAfiliado' => 'CC',
                'documentoAfiliado' => '999999999',
                'primerNombreAfiliado' => 'AFILIADO',
                'segundoNombreAfiliado' => 'FICTICIO',
                'primerApellidoAfiliado' => 'PRUEBA',
                'segundoApellidoAfiliado' => 'DEMO',
                'estadoAfiliacion' => 'Activo',
                'regimen' => 'SUBSIDIADO',
                'email' => 'demo@ejemplo.com',
            ], $extra)],
        ];
    }

    public function test_genera_el_token_y_consulta_el_afiliado(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A', 'expires_in' => 3600]),
            self::URL_CONSULTA => Http::response($this->afiliadoDeEjemplo()),
        ]);

        $respuesta = $this->cliente()->consultarAfiliado([
            'tipoDocumento' => 'CC',
            'numeroDocumento' => '999999999',
        ]);

        $this->assertTrue($respuesta->exitosa());
        $this->assertSame('0', $respuesta->codigo());
        $this->assertSame(1, $respuesta->registros());
        $this->assertSame('PRUEBA', $respuesta->primerAfiliado()['primerApellidoAfiliado']);

        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'consultar-afiliado')
            && $peticion->hasHeader('Authorization', 'Bearer TOKEN-A'));
    }

    public function test_guarda_el_token_en_cache_hasta_que_vence(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A', 'expires_in' => 3600]),
            self::URL_CONSULTA => Http::response($this->afiliadoDeEjemplo()),
        ]);

        $cliente = $this->cliente();
        $cliente->consultarAfiliado(['tipoDocumento' => 'CC', 'numeroDocumento' => '1']);

        $this->assertSame('TOKEN-A', Cache::get(SaviaClient::CLAVE_CACHE_TOKEN));

        [, $origen] = $cliente->token();
        $this->assertSame('caché', $origen);

        $cliente->consultarAfiliado(['tipoDocumento' => 'CC', 'numeroDocumento' => '2']);

        $peticionesDeToken = 0;
        Http::assertSent(function ($peticion) use (&$peticionesDeToken) {
            if (str_contains($peticion->url(), 'token/generacion')) {
                $peticionesDeToken++;
            }

            return true;
        });

        $this->assertSame(1, $peticionesDeToken, 'El token debía pedirse una sola vez.');
    }

    public function test_renueva_el_token_y_reintenta_una_sola_vez_tras_un_401(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::sequence()
                ->push(['access_token' => 'TOKEN-VENCIDO'])
                ->push(['access_token' => 'TOKEN-NUEVO']),
            self::URL_CONSULTA => Http::sequence()
                ->push(['mensaje' => 'Token inválido'], 401)
                ->push($this->afiliadoDeEjemplo()),
        ]);

        $respuesta = $this->cliente()->consultarAfiliado([
            'tipoDocumento' => 'CC',
            'numeroDocumento' => '999999999',
        ]);

        $this->assertTrue($respuesta->exitosa());
        $this->assertSame('renovado tras 401', $respuesta->origenToken);

        $consultas = 0;
        Http::assertSent(function ($peticion) use (&$consultas) {
            if (str_contains($peticion->url(), 'consultar-afiliado')) {
                $consultas++;
            }

            return true;
        });

        $this->assertSame(2, $consultas, 'Debía reintentar exactamente una vez.');

        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'consultar-afiliado')
            && $peticion->hasHeader('Authorization', 'Bearer TOKEN-NUEVO'));
    }

    public function test_no_reintenta_mas_de_una_vez_si_el_401_persiste(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => Http::response(['mensaje' => 'Token inválido'], 401),
        ]);

        $respuesta = $this->cliente()->consultarAfiliado([
            'tipoDocumento' => 'CC',
            'numeroDocumento' => '999999999',
        ]);

        $this->assertFalse($respuesta->exitosa());
        $this->assertSame(401, $respuesta->httpCode);

        $consultas = 0;
        Http::assertSent(function ($peticion) use (&$consultas) {
            if (str_contains($peticion->url(), 'consultar-afiliado')) {
                $consultas++;
            }

            return true;
        });

        $this->assertSame(2, $consultas, 'Un 401 que persiste no debe reintentarse en bucle.');

        $this->assertSame('No autorizado', substr($respuesta->diagnostico()['detalle'], 0, 13));
    }

    public function test_informa_en_espanol_cuando_el_afiliado_no_existe(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => Http::response([
                'registros' => 0,
                'codigo' => -1000,
                'mensaje' => 'Afiliado no encontrado',
                'afiliados' => [],
            ], 404),
        ]);

        $respuesta = $this->cliente()->consultarAfiliado([
            'tipoDocumento' => 'CC',
            'numeroDocumento' => '111111111',
        ]);

        $this->assertFalse($respuesta->exitosa());
        $this->assertSame(0, $respuesta->registros());
        $this->assertNull($respuesta->primerAfiliado());

        $diagnostico = $respuesta->diagnostico();
        $this->assertSame('warn', $diagnostico['tono']);
        $this->assertSame('Afiliado no encontrado', $diagnostico['titulo']);
        $this->assertStringContainsString('Código interno -1000', $diagnostico['detalle']);
    }

    /**
     * Comprobado contra el ambiente de pruebas: cuando el afiliado no existe
     * el servicio responde HTTP 200 (no 404) y marca el caso con el código
     * interno -1000. Por eso `exitosa()` exige código 0 y no solo un HTTP 200.
     */
    public function test_el_no_encontrado_llega_como_http_200_con_codigo_menos_1000(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => Http::response([
                'registros' => 0,
                'codigo' => -1000,
                'mensaje' => 'Afiliado no encontrado',
                'afiliados' => [],
            ], 200),
        ]);

        $respuesta = $this->cliente()->consultarAfiliado([
            'tipoDocumento' => 'CC',
            'numeroDocumento' => '999999999',
        ]);

        $this->assertSame(200, $respuesta->httpCode);
        $this->assertFalse($respuesta->exitosa(), 'Un HTTP 200 con código -1000 no es una consulta exitosa.');
        $this->assertNull($respuesta->primerAfiliado());
        $this->assertSame('warn', $respuesta->diagnostico()['tono']);
        $this->assertSame('Afiliado no encontrado', $respuesta->diagnostico()['titulo']);
    }

    public function test_informa_cuando_el_endpoint_de_token_rechaza_las_credenciales(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['error' => 'credenciales'], 401),
            self::URL_CONSULTA => Http::response($this->afiliadoDeEjemplo()),
        ]);

        $respuesta = $this->cliente()->consultarAfiliado([
            'tipoDocumento' => 'CC',
            'numeroDocumento' => '999999999',
        ]);

        $this->assertFalse($respuesta->exitosa());
        $this->assertSame('No se pudo obtener el token', $respuesta->diagnostico()['titulo']);

        // Sin token no se gasta una llamada a la consulta.
        Http::assertNotSent(fn ($peticion) => str_contains($peticion->url(), 'consultar-afiliado'));
    }

    public function test_informa_cuando_el_servicio_no_responde_sin_lanzar_excepcion(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => fn () => throw new ConnectionException(
                'cURL error 28: Operation timed out'
            ),
        ]);

        $respuesta = $this->cliente()->consultarAfiliado([
            'tipoDocumento' => 'CC',
            'numeroDocumento' => '999999999',
        ]);

        $this->assertFalse($respuesta->exitosa());
        $this->assertSame(0, $respuesta->httpCode);

        $diagnostico = $respuesta->diagnostico();
        $this->assertSame('No se pudo contactar el servicio', $diagnostico['titulo']);
        $this->assertStringContainsString('puerto 8081', $diagnostico['detalle']);
    }

    public function test_el_payload_lleva_los_siete_atributos_del_documento(): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => Http::response($this->afiliadoDeEjemplo()),
        ]);

        $this->cliente()->consultarAfiliado([
            'tipoDocumento' => 'cc',
            'numeroDocumento' => '662301',
        ]);

        Http::assertSent(function ($peticion) {
            if (! str_contains($peticion->url(), 'consultar-afiliado')) {
                return false;
            }

            $cuerpo = $peticion->data();

            return array_keys($cuerpo) === config('savia.campos_peticion')
                && $cuerpo['tipoDocumento'] === 'CC'          // se normaliza a mayúsculas
                && $cuerpo['fechaNacimiento'] === ''          // los vacíos viajan como cadena vacía
                && $cuerpo['segundoApellido'] === '';
        });
    }

    public function test_normaliza_el_campo_programas_que_devuelve_el_servicio(): void
    {
        // La V3 documenta "programasEspeciales" pero el servicio responde "programas".
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => Http::response($this->afiliadoDeEjemplo([
                'programas' => [
                    ['tipo' => 'RIAS', 'descripcion' => 'RIAS VISUAL'],
                    ['tipo' => 'Programa Especial', 'descripcion' => 'Riesgo Cardiovascular'],
                ],
            ])),
        ]);

        $afiliado = $this->cliente()->consultarAfiliado([
            'tipoDocumento' => 'CC',
            'numeroDocumento' => '999999999',
        ])->primerAfiliado();

        $this->assertArrayNotHasKey('programas', $afiliado);
        $this->assertCount(2, $afiliado['programasEspeciales']);
        $this->assertSame('RIAS VISUAL', $afiliado['programasEspeciales'][0]['descripcion']);
    }

    public function test_repara_las_descripciones_de_programas_que_savia_devuelve_mal_codificadas(): void
    {
        // El servicio devuelve bien el resto de la respuesta, pero las
        // descripciones de programas llegan con el UTF-8 codificado dos veces.
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => Http::response($this->afiliadoDeEjemplo([
                'grupoPoblacional' => 'Población sisbenizada',
                'programas' => [
                    ['tipo' => 'Programa Especial', 'descripcion' => "Atenci\xC3\x83\xC2\xB3n domiciliaria"],
                    ['tipo' => 'RIAS', 'descripcion' => 'RIAS VISUAL'],
                ],
            ])),
        ]);

        $afiliado = $this->cliente()->consultarAfiliado([
            'tipoDocumento' => 'CC',
            'numeroDocumento' => '999999999',
        ])->primerAfiliado();

        $this->assertSame('Atención domiciliaria', $afiliado['programasEspeciales'][0]['descripcion']);

        // Lo que ya venía bien no se toca.
        $this->assertSame('RIAS VISUAL', $afiliado['programasEspeciales'][1]['descripcion']);
        $this->assertSame('Población sisbenizada', $afiliado['grupoPoblacional']);
    }

    /**
     * Un texto bien codificado debe salir idéntico del reparador.
     */
    public function test_el_reparador_no_altera_el_texto_bien_codificado(): void
    {
        foreach (['Población sisbenizada', 'MEDELLÍN', 'Atención domiciliaria', 'ANTIOQUIA', 'Niño', ''] as $texto) {
            $this->assertSame($texto, RespuestaConsulta::repararTexto($texto));
        }
    }

    public function test_la_auditoria_registra_quien_consulto_y_que_documento_sin_datos_de_salud(): void
    {
        config(['savia.auditar' => true]);

        // Sin base de datos: solo interesa que auth()->id() quede en el log.
        $usuario = new User;
        $usuario->id = 7;
        $this->actingAs($usuario);

        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A']),
            self::URL_CONSULTA => Http::response($this->afiliadoDeEjemplo()),
        ]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $mensaje, array $contexto): bool {
                $this->assertSame('Consulta afiliado Savia', $mensaje);

                // Quién, cuándo (lo pone el log) y qué documento.
                $this->assertSame(7, $contexto['usuario']);
                $this->assertSame('CC', $contexto['tipoDocumento']);
                $this->assertSame('999999999', $contexto['numeroDocumento']);

                // Nunca la respuesta completa: son datos de salud.
                $serializado = json_encode($contexto, JSON_UNESCAPED_UNICODE);
                foreach (['AFILIADO', 'PRUEBA', 'SUBSIDIADO', 'demo@ejemplo.com', 'Activo'] as $dato) {
                    $this->assertStringNotContainsString($dato, $serializado);
                }

                return true;
            });

        $this->cliente()->consultarAfiliado([
            'tipoDocumento' => 'CC',
            'numeroDocumento' => '999999999',
        ]);
    }
}
