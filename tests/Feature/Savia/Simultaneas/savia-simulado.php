<?php

/*
 * Savia simulado para `ConsultasSimultaneasTest`. Corre con `php -S` como un
 * servidor aparte, así que las consultas le llegan por HTTP de verdad desde
 * procesos de PHP distintos.
 *
 * Se porta como el caso peligroso: **emitir un token anula el anterior**. Si
 * varias consultas piden cada una su token, se los anulan unas a otras.
 *
 * - POST /token     tarda un poco, emite TOKEN-<n> y lo deja como el único vigente.
 * - POST /consulta  responde el afiliado solo con el token vigente; si no, 401.
 *
 * Lleva la cuenta en SAVIA_SIMULADO_DIR/estado.json, con candado de archivo.
 */

$directorio = getenv('SAVIA_SIMULADO_DIR');
$estado = $directorio.'/estado.json';
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$conCandado = function (callable $hacer) use ($estado) {
    $candado = fopen($estado.'.lock', 'c');
    flock($candado, LOCK_EX);

    try {
        $actual = is_file($estado) ? json_decode((string) file_get_contents($estado), true) : [];
        $actual += ['emitidos' => 0, 'vigente' => null, 'consultas' => 0, 'rechazadas' => 0];
        [$respuesta, $nuevo] = $hacer($actual);
        file_put_contents($estado, json_encode($nuevo));

        return $respuesta;
    } finally {
        flock($candado, LOCK_UN);
        fclose($candado);
    }
};

header('Content-Type: application/json');

if ($ruta === '/token') {
    usleep(600_000); // Savia se toma su tiempo para entregar el token

    $token = $conCandado(function (array $e): array {
        $e['emitidos']++;
        $e['vigente'] = 'TOKEN-'.$e['emitidos'];

        return [$e['vigente'], $e];
    });

    echo json_encode(['access_token' => $token, 'expires_in' => 3600]);

    return;
}

if ($ruta === '/consulta') {
    usleep(100_000);

    $cabeceras = array_change_key_case(getallheaders(), CASE_LOWER);
    $recibido = $cabeceras['authorization'] ?? '';

    $valido = $conCandado(function (array $e) use ($recibido): array {
        $valido = $e['vigente'] !== null && $recibido === 'Bearer '.$e['vigente'];
        $valido ? $e['consultas']++ : $e['rechazadas']++;

        return [$valido, $e];
    });

    if (! $valido) {
        http_response_code(401);
        echo json_encode(['codigo' => -1, 'mensaje' => 'Token inválido']);

        return;
    }

    echo json_encode([
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
        ]],
    ]);

    return;
}

http_response_code(404);
echo json_encode(['mensaje' => 'No existe']);
