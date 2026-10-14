<?php

namespace App\Console\Commands;

use App\Models\Auditoria;
use App\Services\Savia\SaviaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Comprueba en el servidor que la conexión con Savia funciona.
 *
 *   php artisan savia:probar               pide un token nuevo
 *   php artisan savia:probar 1000873458    y además consulta ese documento
 *
 * Dice qué dirección y qué ambiente está usando, si hay credenciales (nunca
 * cuáles), si Savia entregó el token y, con un documento, si lo encontró y el
 * código de respuesta. **Nunca muestra datos del afiliado.**
 *
 * El token nuevo queda en uso, como cuando lo renueva una consulta: pasa por
 * el mismo candado, así que no estorba a quien esté consultando. Una consulta
 * con documento queda en la auditoría, a nombre de «Sistema».
 */
class ProbarSavia extends Command
{
    protected $signature = 'savia:probar
        {documento? : Número de documento para probar también una consulta}
        {--tipo=CC : Tipo de documento (CC, TI, CE, PA…)}';

    protected $description = 'Comprueba las credenciales y la conexión con Savia; con un documento, dice si lo encontró';

    public function handle(): int
    {
        $cfg = (array) config('savia');
        $cliente = SaviaClient::desdeConfig();

        $this->mostrarConfiguracion($cfg, $cliente);

        if (! $cliente->configurado()) {
            $this->error('Faltan SAVIA_USERNAME y SAVIA_PASSWORD en el .env.');
            $this->line('  Ponlas en el .env del servidor y corre: php artisan optimize');

            return self::FAILURE;
        }

        if (($hasta = $cliente->pausadoHasta()) !== null) {
            $this->warn('Las consultas están en pausa hasta las '.date('H:i:s', $hasta).' por varias fallas seguidas de Savia.');
            $this->line('  Espera a esa hora y vuelve a probar.');

            return self::FAILURE;
        }

        if (! $this->probarToken($cliente)) {
            return self::FAILURE;
        }

        $documento = $this->argument('documento');

        return $documento === null ? self::SUCCESS : $this->probarConsulta($cliente, (string) $documento);
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function mostrarConfiguracion(array $cfg, SaviaClient $cliente): void
    {
        $endpoint = (string) ($cfg['endpoint'] ?? '');
        $pruebas = str_contains($endpoint, 'pruebas');

        $this->line('');
        $this->line('<options=bold>Savia — Consulta Afiliados</>');
        $this->line('  Ambiente:      '.($pruebas ? '<fg=yellow>PRUEBAS</>' : 'producción'));
        $this->line('  Token:         '.($cfg['endpoint_token'] ?? '—'));
        $this->line('  Consulta:      '.($endpoint ?: '—'));
        $this->line('  Credenciales:  '.($cliente->hayCredenciales() ? 'configuradas' : '<fg=red>faltan</>'));
        $this->line('  Certificado:   '.(($cfg['verify_ssl'] ?? false) ? 'se verifica' : '<fg=yellow>no se verifica</>'));
        $this->line('  Tiempo límite: '.($cfg['timeout'] ?? '—').' s (conectar: '.($cfg['connect_timeout'] ?? '—').' s)');

        if (app()->configurationIsCached()) {
            $this->line('  <fg=gray>La configuración está en caché: si cambiaste el .env, corre php artisan optimize.</>');
        }

        $this->line('');
    }

    /**
     * Pide un token nuevo aunque haya uno en caché: es lo que comprueba las
     * credenciales. Pasa por el mismo candado que las consultas.
     */
    private function probarToken(SaviaClient $cliente): bool
    {
        if (! $cliente->hayCredenciales()) {
            $this->info('✓ Se usa un token fijo (SAVIA_TOKEN): no hay token que pedir.');

            return true;
        }

        $inicio = microtime(true);
        [$token, $motivo] = $cliente->token(rechazado: (string) Cache::get(SaviaClient::CLAVE_CACHE_TOKEN));
        $ms = (int) round((microtime(true) - $inicio) * 1000);

        if ($token === null) {
            $this->error('✗ Savia no entregó el token.');
            $this->line('  '.$motivo);
            $this->pista($motivo);

            return false;
        }

        $this->info("✓ Savia entregó un token ({$ms} ms). Las credenciales funcionan.");

        return true;
    }

    private function probarConsulta(SaviaClient $cliente, string $documento): int
    {
        $tipo = strtoupper((string) $this->option('tipo'));

        if (! array_key_exists($tipo, (array) config('savia.tipos_documento'))) {
            $this->error("«{$tipo}» no es un tipo de documento de Savia. Usa CC, TI, CE, PA…");

            return self::FAILURE;
        }

        if (! preg_match('/^[A-Za-z0-9\-]{1,20}$/', $documento)) {
            $this->error('El documento solo admite letras, números y guion, hasta 20 caracteres.');

            return self::FAILURE;
        }

        $respuesta = $cliente->consultarAfiliado(['tipoDocumento' => $tipo, 'numeroDocumento' => $documento]);
        $encontrado = $respuesta->exitosa() && $respuesta->primerAfiliado() !== null;

        // Consultar un documento real es tocar un dato de salud: queda en la
        // auditoría como cualquier consulta, a nombre de «Sistema».
        Auditoria::registrar(
            accion: Auditoria::ACCION_CONSULTO_SAVIA,
            descripcion: "Consultó en Savia el documento {$tipo} {$documento} desde la consola (savia:probar)"
                .($encontrado ? '' : ' (sin resultado)'),
            entidadTipo: 'paciente',
        );

        $codigo = $respuesta->codigo();
        $texto = $codigo !== null ? (config("savia.codigos.{$codigo}.texto") ?? 'código no documentado') : 'sin código';

        $this->line("<options=bold>Consulta de {$tipo} {$documento}</>");
        $this->line('  HTTP:      '.($respuesta->httpCode ?: 'sin respuesta'));
        $this->line('  Código:    '.($codigo ?? '—')." ({$texto})");
        $this->line('  ¿Lo encontró? '.($encontrado ? 'Sí' : 'No'));
        $this->line("  Tiempo:    {$respuesta->duracionMs} ms");

        if ($respuesta->esFallaTecnica()) {
            $diagnostico = $respuesta->diagnostico();
            $this->error("✗ {$diagnostico['titulo']}");
            $this->line('  '.$diagnostico['detalle']);
            $this->pista($diagnostico['detalle'].' '.($respuesta->errorRed ?? $respuesta->errorToken ?? '').' HTTP '.$respuesta->httpCode);

            return self::FAILURE;
        }

        $this->info('✓ La consulta funciona.');

        return self::SUCCESS;
    }

    /** Qué revisar, según cómo falló. */
    private function pista(string $error): void
    {
        $error = strtolower($error);

        $pista = match (true) {
            str_contains($error, 'ssl') || str_contains($error, 'certificate') => 'No se pudo verificar el certificado de Savia. Confirma con ellos que la dirección es la de producción.',
            str_contains($error, 'timed out') || str_contains($error, 'failed to connect') || str_contains($error, 'reset') || str_contains($error, 'tardando') => 'Revisa que el servidor pueda salir a esa dirección y puerto, y que Savia haya autorizado su IP pública (curl -4 ifconfig.me).',
            str_contains($error, 'could not resolve') || str_contains($error, 'resolve host') => 'El servidor no encuentra esa dirección: revisa SAVIA_ENDPOINT y SAVIA_ENDPOINT_TOKEN en el .env.',
            // Antes que el 401: el mensaje de un 404 al pedir el token también dice «token».
            str_contains($error, '404') => 'La dirección no existe: revisa SAVIA_ENDPOINT y SAVIA_ENDPOINT_TOKEN en el .env (la del token, confírmala con Savia).',
            str_contains($error, '401') || str_contains($error, 'token') => 'Revisa SAVIA_USERNAME y SAVIA_PASSWORD: tienen que ser las de producción. Si Savia confirma que están bien, puede que no haya autorizado la IP del servidor.',
            default => null,
        };

        if ($pista !== null) {
            $this->line("  <fg=yellow>Qué revisar:</> {$pista}");
        }

        $this->line('  Después de cambiar el .env: php artisan optimize');
    }
}
