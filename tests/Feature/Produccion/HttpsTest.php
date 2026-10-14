<?php

namespace Tests\Feature\Produccion;

use App\Providers\HttpsServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * En producción todo sale con https, aunque haya un proxy delante que le
 * hable a la aplicación por http.
 */
class HttpsTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        URL::forceScheme(null);

        parent::tearDown();
    }

    private function arrancar(string $appUrl, ?string $proxies = null): void
    {
        config(['app.url' => $appUrl, 'sispam.proxies_de_confianza' => $proxies]);

        (new HttpsServiceProvider($this->app))->boot();
    }

    public function test_con_app_url_https_las_urls_salen_con_https(): void
    {
        $this->arrancar('https://consultasavia.comitedeestudiosmedicos.com.co');

        $this->assertStringStartsWith('https://', url('/admin'));
        $this->assertStringStartsWith('https://', asset('css/sispam-tema.css'));
        $this->assertStringStartsWith('https://', route('auth.authentik.callback'));
    }

    public function test_en_local_no_se_fuerza_nada(): void
    {
        $this->arrancar('http://localhost:8000');

        $this->assertStringStartsWith('http://', url('/admin'));
    }

    public function test_detras_de_un_proxy_de_confianza_la_peticion_cuenta_como_https(): void
    {
        // APP_URL en http: lo único que puede volverla segura es la cabecera del proxy.
        $this->arrancar('http://localhost', proxies: '10.0.0.5');
        Route::get('/prueba-https', fn () => ['segura' => request()->isSecure(), 'ip' => request()->ip()]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '190.85.1.2'])
            ->get('/prueba-https')
            ->assertExactJson(['segura' => true, 'ip' => '190.85.1.2']);
    }

    public function test_a_un_proxy_que_no_es_de_confianza_no_se_le_cree(): void
    {
        // APP_URL en http: lo único que puede volverla segura es la cabecera del proxy.
        $this->arrancar('http://localhost', proxies: '10.0.0.5');
        Route::get('/prueba-https', fn () => ['segura' => request()->isSecure(), 'ip' => request()->ip()]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '190.85.1.2'])
            ->get('/prueba-https')
            ->assertExactJson(['segura' => false, 'ip' => '203.0.113.9']);
    }
}
