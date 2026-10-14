<?php

namespace Tests\Feature\Savia;

use App\Services\Savia\SaviaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Varias personas consultando a la vez, con procesos de PHP de verdad.
 *
 * `Http::fake()` corre todo en un solo proceso, así que no puede mostrar dos
 * peticiones pidiendo el token al mismo tiempo. Aquí sí: un Savia simulado
 * corre como servidor aparte (`php -S`) y cada consulta es un proceso de PHP
 * con su propia aplicación, compartiendo la caché como en el servidor.
 *
 * El Savia simulado **anula el token anterior cada vez que emite uno**: si las
 * consultas pidieran cada una el suyo, se los anularían unas a otras. Por eso
 * la prueba exige que emita uno solo.
 *
 * Tarda unos segundos: arranca procesos reales.
 */
class ConsultasSimultaneasTest extends TestCase
{
    private const CONSULTAS = 8;

    private string $directorio;

    private string $url;

    private Process $savia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directorio = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sispam-savia-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->directorio.'/cache');

        $puerto = $this->puertoLibre();
        $this->url = "http://127.0.0.1:{$puerto}";

        $this->savia = new Process(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", __DIR__.'/Simultaneas/savia-simulado.php'],
            env: ['SAVIA_SIMULADO_DIR' => $this->directorio],
        );
        $this->savia->start();
        $this->esperarA(fn (): bool => @fsockopen('127.0.0.1', $puerto) !== false, 'El Savia simulado no arrancó.');
    }

    protected function tearDown(): void
    {
        $this->savia->stop(0);
        File::deleteDirectory($this->directorio);

        parent::tearDown();
    }

    public function test_varias_consultas_a_la_vez_piden_un_solo_token(): void
    {
        $resultados = $this->consultarAlMismoTiempo();

        $this->assertTodasRespondieron($resultados);
        $this->assertSame(1, $this->estadoDeSavia()['emitidos'], 'Savia emitió más de un token.');
    }

    public function test_con_el_token_vencido_una_sola_consulta_lo_renueva(): void
    {
        // Lo que queda en la caché cuando el token vence del lado de Savia:
        // a todas les va a responder 401.
        Cache::build(['driver' => 'file', 'path' => $this->directorio.'/cache'])
            ->put(SaviaClient::CLAVE_CACHE_TOKEN, 'TOKEN-VENCIDO', 3600);

        $resultados = $this->consultarAlMismoTiempo();

        $this->assertTodasRespondieron($resultados);
        $this->assertGreaterThan(0, $this->estadoDeSavia()['rechazadas']);
        $this->assertSame(1, $this->estadoDeSavia()['emitidos'], 'Savia emitió más de un token.');
    }

    /**
     * Lanza las consultas, espera a que todas estén listas y les da la señal
     * de salida a la vez.
     *
     * @return list<array{http: int, registros: int, error: ?string}>
     */
    private function consultarAlMismoTiempo(): array
    {
        $procesos = [];

        for ($i = 1; $i <= self::CONSULTAS; $i++) {
            $procesos[$i] = new Process(
                [PHP_BINARY, __DIR__.'/Simultaneas/consultar.php', $this->directorio, $this->url, (string) $i],
                env: [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'sqlite',
                    'DB_DATABASE' => ':memory:',
                    'CACHE_STORE' => 'file',
                    'SESSION_DRIVER' => 'array',
                    'QUEUE_CONNECTION' => 'sync',
                    'LOG_CHANNEL' => 'null',
                ],
                timeout: 60,
            );
            $procesos[$i]->start();
        }

        $this->esperarA(function () use ($procesos): bool {
            foreach ($procesos as $i => $proceso) {
                if (! $proceso->isRunning() && ! $proceso->isSuccessful()) {
                    $this->fail("La consulta {$i} no arrancó: ".$proceso->getErrorOutput().$proceso->getOutput());
                }
            }

            return count(glob($this->directorio.'/listo-*')) === self::CONSULTAS;
        }, 'Las consultas no estuvieron listas a tiempo.', segundos: 60);

        touch($this->directorio.'/ya');

        return array_values(array_map(function (Process $proceso): array {
            $proceso->wait();

            return json_decode($proceso->getOutput(), true)
                ?? $this->fail('Una consulta no respondió: '.$proceso->getErrorOutput().$proceso->getOutput());
        }, $procesos));
    }

    /**
     * @param  list<array{http: int, registros: int, error: ?string}>  $resultados
     */
    private function assertTodasRespondieron(array $resultados): void
    {
        $this->assertCount(self::CONSULTAS, $resultados);

        foreach ($resultados as $resultado) {
            $this->assertSame(200, $resultado['http'], 'Una consulta falló: '.json_encode($resultado, JSON_UNESCAPED_UNICODE));
            $this->assertSame(1, $resultado['registros']);
        }
    }

    /**
     * @return array{emitidos: int, vigente: ?string, consultas: int, rechazadas: int}
     */
    private function estadoDeSavia(): array
    {
        return json_decode((string) file_get_contents($this->directorio.'/estado.json'), true);
    }

    private function puertoLibre(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $puerto;
    }

    private function esperarA(callable $condicion, string $mensaje, int $segundos = 10): void
    {
        $limite = microtime(true) + $segundos;

        while (! $condicion()) {
            if (microtime(true) > $limite) {
                $this->fail($mensaje);
            }

            usleep(20_000);
        }
    }
}
