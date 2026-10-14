<?php

namespace Tests\Feature\Produccion;

use App\Models\Sede;
use App\Models\User;
use Database\Seeders\RolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Con APP_DEBUG=false los errores salen en español, con el estilo de SISPAM, y
 * sin nada de adentro: ni el mensaje de la excepción, ni la consulta SQL, ni
 * rutas del servidor.
 */
class PaginasDeErrorTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'SQLSTATE[42S02] tabla pacientes en /var/www/sispam/app/Secreto.php';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        Route::get('/prueba-error/{codigo}', fn (int $codigo) => abort($codigo, self::SECRETO));
        Route::get('/prueba-excepcion', fn () => throw new RuntimeException(self::SECRETO));
        Route::post('/prueba-excepcion', fn () => throw new RuntimeException(self::SECRETO));
    }

    /**
     * @testWith [403, "No tienes permiso para entrar aquí"]
     *           [404, "No encontramos esta página"]
     *           [419, "La página expiró"]
     *           [429, "Demasiadas solicitudes"]
     *           [500, "Algo falló en SISPAM"]
     *           [503, "SISPAM está en mantenimiento"]
     *           [418, "No se pudo completar la solicitud"]
     *           [502, "Algo falló en SISPAM"]
     */
    public function test_cada_error_sale_en_espanol_y_sin_el_mensaje_interno(int $codigo, string $titulo): void
    {
        $this->get("/prueba-error/{$codigo}")
            ->assertStatus($codigo)
            ->assertSee("ERROR {$codigo}")
            ->assertSee($titulo)
            ->assertSee('COMITÉ DE')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('/var/www');
    }

    public function test_una_excepcion_no_muestra_nada_de_adentro(): void
    {
        $this->get('/prueba-excepcion')
            ->assertStatus(500)
            ->assertSee('Algo falló en SISPAM')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('RuntimeException')
            ->assertDontSee('Secreto.php');
    }

    public function test_tampoco_en_lo_que_recibe_livewire(): void
    {
        // Livewire pide JSON: lo que reciba es lo que el navegador podría ver.
        $this->postJson('/prueba-excepcion')
            ->assertStatus(500)
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('RuntimeException');
    }

    public function test_una_pantalla_sin_permiso_del_panel_tambien_sale_en_espanol(): void
    {
        $this->seed(RolSeeder::class);
        $this->actingAs(User::factory()->create([
            'roles' => ['CONSULTA'],
            'cuenta_compartida' => true,
            'sede_id' => Sede::factory()->create()->id,
        ]));

        $this->get('/admin/pacientes')
            ->assertForbidden()
            ->assertSee('No tienes permiso para entrar aquí');
    }

    public function test_en_produccion_los_errores_de_livewire_salen_como_notificacion(): void
    {
        $this->actingAs(User::factory()->administrador()->create());

        $this->get('/admin')->assertOk()->assertSee('new FilamentNotification()', escape: false);

        config(['app.debug' => true]);

        $this->get('/admin')->assertOk()->assertDontSee('new FilamentNotification()', escape: false);
    }
}
