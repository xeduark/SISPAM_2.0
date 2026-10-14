<?php

namespace Tests\Feature\Produccion;

use App\Models\Rol;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * El `.env.production.example` y la carpeta `public/`: lo que se sube al
 * repositorio y lo que queda publicado en internet.
 */
class ConfiguracionDeProduccionTest extends TestCase
{
    /** @return array<string, string> */
    private function envDeProduccion(): array
    {
        return Dotenv::parse((string) file_get_contents(base_path('.env.production.example')));
    }

    public function test_el_ejemplo_de_produccion_no_trae_ningun_secreto(): void
    {
        $env = $this->envDeProduccion();

        foreach ([
            'APP_KEY', 'DB_PASSWORD',
            'AUTHENTIK_CLIENT_ID', 'AUTHENTIK_CLIENT_SECRET',
            'SAVIA_USERNAME', 'SAVIA_PASSWORD',
            'GOOGLE_VISION_API_KEY', 'GEMINI_API_KEY',
            'INVENTARIO_API_URL', 'INVENTARIO_API_TOKEN',
        ] as $secreto) {
            $this->assertArrayHasKey($secreto, $env, "Falta {$secreto} en el ejemplo.");
            $this->assertSame('', $env[$secreto], "{$secreto} tiene que ir vacío en el repositorio.");
        }
    }

    public function test_el_ejemplo_de_produccion_trae_lo_que_cambia_frente_al_local(): void
    {
        $env = $this->envDeProduccion();

        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('https://consultasavia.comitedeestudiosmedicos.com.co', $env['APP_URL']);
        $this->assertSame('warning', $env['LOG_LEVEL']);
        $this->assertSame('true', $env['SESSION_SECURE_COOKIE']);
        $this->assertSame('true', $env['SAVIA_VERIFY_SSL']);
        $this->assertSame('10', $env['SAVIA_TIMEOUT']);
        $this->assertSame('database', $env['CACHE_STORE'], 'El token de Savia necesita una caché con candados.');
    }

    public function test_los_modulos_encendidos_existen(): void
    {
        $modulos = explode(',', $this->envDeProduccion()['SISPAM_MODULOS']);

        // Una clave mal escrita apagaría ese módulo sin avisar.
        $this->assertSame([], array_diff($modulos, array_keys(Rol::MODULOS)));
        $this->assertEqualsCanonicalizing(['consultar_paciente', 'usuarios', 'sedes', 'auditoria'], $modulos);
    }

    public function test_en_public_no_hay_documentos_ni_datos(): void
    {
        // Todo lo que está en public/ se descarga sin iniciar sesión.
        $prohibidos = collect(File::allFiles(public_path()))
            ->filter(fn ($archivo): bool => in_array(strtolower($archivo->getExtension()), [
                'pdf', 'xls', 'xlsx', 'csv', 'doc', 'docx', 'sql', 'zip', 'json', 'env', 'log', 'bak',
            ], true))
            ->map(fn ($archivo): string => $archivo->getRelativePathname())
            ->values()
            ->all();

        $this->assertSame([], $prohibidos);
    }

    public function test_los_buscadores_no_indexan_sispam(): void
    {
        $this->assertStringContainsString('Disallow: /', (string) file_get_contents(public_path('robots.txt')));
    }
}
