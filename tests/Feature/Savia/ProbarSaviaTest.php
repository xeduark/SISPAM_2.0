<?php

namespace Tests\Feature\Savia;

use App\Models\Auditoria;
use App\Services\Savia\SaviaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `php artisan savia:probar`: para revisar en el servidor que las
 * credenciales y la conexión con Savia funcionan, sin mostrar datos del afiliado.
 */
class ProbarSaviaTest extends TestCase
{
    use RefreshDatabase;

    private const URL_TOKEN = '*rest/token/generacion';

    private const URL_CONSULTA = '*rest/afiliado/consultar-afiliado';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'savia.endpoint' => 'https://intersavia.saviasaludeps.com/intersavia-webservices/rest/afiliado/consultar-afiliado',
            'savia.endpoint_token' => 'https://intersavia.saviasaludeps.com/intersavia-webservices/rest/token/generacion',
            'savia.username' => '900000000',
            'savia.password' => 'clave-secreta-de-produccion',
            'savia.token' => null,
            'savia.auditar' => false,
        ]);
    }

    private function saviaResponde(array $consulta = [], int $http = 200): void
    {
        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-NUEVO', 'expires_in' => 3600]),
            self::URL_CONSULTA => Http::response($consulta, $http),
        ]);
    }

    public function test_sin_documento_pide_un_token_nuevo_y_dice_que_funciona(): void
    {
        Cache::put(SaviaClient::CLAVE_CACHE_TOKEN, 'TOKEN-VIEJO', 3600);
        $this->saviaResponde();

        $this->artisan('savia:probar')
            ->expectsOutputToContain('Ambiente:      producción')
            ->expectsOutputToContain('Credenciales:  configuradas')
            ->expectsOutputToContain('Las credenciales funcionan')
            ->doesntExpectOutputToContain('clave-secreta-de-produccion')
            ->doesntExpectOutputToContain('900000000')
            ->assertSuccessful();

        // Pidió uno nuevo aunque había otro en caché, y quedó en uso.
        Http::assertSentCount(1);
        $this->assertSame('TOKEN-NUEVO', Cache::get(SaviaClient::CLAVE_CACHE_TOKEN));
    }

    public function test_dice_cuando_las_credenciales_no_sirven(): void
    {
        Http::fake([self::URL_TOKEN => Http::response(['error' => 'invalid_grant'], 401)]);

        $this->artisan('savia:probar')
            ->expectsOutputToContain('Savia no entregó el token')
            ->expectsOutputToContain('HTTP 401')
            ->expectsOutputToContain('Revisa SAVIA_USERNAME y SAVIA_PASSWORD')
            ->assertFailed();
    }

    public function test_si_la_direccion_del_token_no_existe_lo_dice(): void
    {
        // La dirección del token de producción se dedujo: si no es, Savia responde 404.
        Http::fake([self::URL_TOKEN => Http::response('Not Found', 404)]);

        $this->artisan('savia:probar')
            ->expectsOutputToContain('HTTP 404')
            ->expectsOutputToContain('La dirección no existe')
            ->doesntExpectOutputToContain('Revisa SAVIA_USERNAME')
            ->assertFailed();
    }

    public function test_dice_cuando_no_hay_conexion(): void
    {
        Http::fake([self::URL_TOKEN => fn () => throw new ConnectionException('cURL error 28: Connection timed out after 5001 milliseconds')]);

        $this->artisan('savia:probar')
            ->expectsOutputToContain('Savia no entregó el token')
            ->expectsOutputToContain('autorizado su IP pública')
            ->assertFailed();
    }

    public function test_sin_credenciales_lo_dice_sin_llamar_a_savia(): void
    {
        config(['savia.username' => null, 'savia.password' => null]);
        Http::fake();

        $this->artisan('savia:probar')
            ->expectsOutputToContain('Faltan SAVIA_USERNAME y SAVIA_PASSWORD')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_avisa_si_esta_apuntando_al_ambiente_de_pruebas(): void
    {
        config(['savia.endpoint' => 'https://intersavia-pruebas.saviasaludeps.com:8081/intersavia-webservices/rest/afiliado/consultar-afiliado']);
        $this->saviaResponde();

        $this->artisan('savia:probar')
            ->expectsOutputToContain('PRUEBAS')
            ->assertSuccessful();
    }

    public function test_con_un_documento_dice_si_lo_encontro_sin_mostrar_datos_del_afiliado(): void
    {
        $this->saviaResponde([
            'registros' => 1,
            'codigo' => 0,
            'mensaje' => 'Afiliado encontrado',
            'afiliados' => [[
                'tipoDocumentoAfiliado' => 'CC',
                'documentoAfiliado' => '1000873458',
                'primerNombreAfiliado' => 'JUAN',
                'primerApellidoAfiliado' => 'RAMIREZ',
                'estadoAfiliacion' => 'Activo',
                'regimen' => 'CONTRIBUTIVO',
                'programas' => [['tipo' => 'Programa Especial', 'descripcion' => 'PROGRAMA VIH']],
            ]],
        ]);

        $this->artisan('savia:probar', ['documento' => '1000873458'])
            ->expectsOutputToContain('¿Lo encontró? Sí')
            ->expectsOutputToContain('Código:    0')
            ->expectsOutputToContain('La consulta funciona')
            ->doesntExpectOutputToContain('JUAN')
            ->doesntExpectOutputToContain('RAMIREZ')
            ->doesntExpectOutputToContain('CONTRIBUTIVO')
            ->doesntExpectOutputToContain('VIH')
            ->assertSuccessful();

        // Consultar un documento real queda en la auditoría.
        $linea = Auditoria::where('accion', Auditoria::ACCION_CONSULTO_SAVIA)->sole();
        $this->assertSame('Sistema', $linea->usuario_nombre);
        $this->assertStringContainsString('CC 1000873458 desde la consola', $linea->descripcion);
    }

    public function test_con_un_documento_que_no_existe_dice_que_no_y_el_codigo(): void
    {
        $this->saviaResponde(['registros' => 0, 'codigo' => -1000, 'mensaje' => 'Afiliado no encontrado', 'afiliados' => []]);

        $this->artisan('savia:probar', ['documento' => '111111111', '--tipo' => 'ti'])
            ->expectsOutputToContain('Consulta de TI 111111111')
            ->expectsOutputToContain('¿Lo encontró? No')
            ->expectsOutputToContain('Código:    -1000 (Afiliado no encontrado en el sistema.)')
            ->assertSuccessful();

        $this->assertStringContainsString('(sin resultado)', Auditoria::sole()->descripcion);
    }

    public function test_si_la_consulta_falla_dice_por_que(): void
    {
        $this->saviaResponde(['codigo' => -1, 'mensaje' => 'MENSAJE: null'], 401);

        $this->artisan('savia:probar', ['documento' => '1000873458'])
            ->expectsOutputToContain('¿Lo encontró? No')
            ->expectsOutputToContain('HTTP:      401')
            ->assertFailed();
    }

    public function test_rechaza_un_documento_o_un_tipo_que_no_sirven(): void
    {
        $this->saviaResponde();

        $this->artisan('savia:probar', ['documento' => '10.008.734'])
            ->expectsOutputToContain('solo admite letras, números y guion')
            ->assertFailed();

        $this->artisan('savia:probar', ['documento' => '1000873458', '--tipo' => 'XX'])
            ->expectsOutputToContain('no es un tipo de documento de Savia')
            ->assertFailed();

        $this->assertSame(0, Auditoria::count());
    }
}
