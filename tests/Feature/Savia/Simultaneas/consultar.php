<?php

/*
 * Una de las consultas simultáneas de `ConsultasSimultaneasTest`: un proceso
 * de PHP aparte, con su propia aplicación Laravel, como una petición más del
 * servidor.
 *
 *   php consultar.php <directorio compartido> <url del Savia simulado> <número>
 *
 * Arranca, avisa que está listo y espera la señal común («ya») para que todas
 * consulten al mismo tiempo. Comparten la caché de archivos del directorio,
 * igual que en el servidor comparten la de la base de datos.
 */

use App\Services\Savia\SaviaClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

[, $directorio, $url, $numero] = $argv;

$raiz = dirname(__DIR__, 4);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'cache.default' => 'file',
    'cache.stores.file.path' => $directorio.'/cache',
    'cache.stores.file.lock_path' => $directorio.'/cache',
    'savia.endpoint' => $url.'/consulta',
    'savia.endpoint_token' => $url.'/token',
    'savia.username' => '900000000',
    'savia.password' => 'clave-de-prueba',
    'savia.token' => null,
    'savia.verify_ssl' => false,
    'savia.auditar' => false,
]);
Cache::forgetDriver('file');

touch("{$directorio}/listo-{$numero}");

$limite = microtime(true) + 30;
while (! file_exists("{$directorio}/ya") && microtime(true) < $limite) {
    usleep(5_000);
}

$respuesta = SaviaClient::desdeConfig()->consultarAfiliado([
    'tipoDocumento' => 'CC',
    'numeroDocumento' => '1000873458',
]);

echo json_encode([
    'http' => $respuesta->httpCode,
    'registros' => $respuesta->exitosa() ? count($respuesta->afiliados()) : 0,
    'error' => $respuesta->errorToken ?? $respuesta->errorRed,
]);
