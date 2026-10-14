<?php

namespace Tests\Feature\Savia;

use App\Filament\Pages\ConsultarPaciente;
use App\Models\Sede;
use App\Models\User;
use Database\Seeders\RolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lo que ve cada quien en Consultar paciente cuando Savia no responde: quien
 * consulta, qué hacer; el administrador, el detalle técnico.
 */
class SaviaCaidaEnPantallaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seed(RolSeeder::class);

        config([
            'savia.username' => '900000000',
            'savia.password' => 'clave-de-prueba',
            'savia.token' => null,
            'savia.auditar' => false,
        ]);

        Http::fake([
            '*rest/token/generacion' => Http::response(['access_token' => 'TOKEN-A']),
            '*rest/afiliado/consultar-afiliado' => fn () => throw new ConnectionException(
                'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received for https://intersavia-pruebas.saviasaludeps.com:8081'
            ),
        ]);
    }

    private function consultar(): Testable
    {
        return Livewire::test(ConsultarPaciente::class)
            ->fillForm(['tipo_documento' => 'CC', 'numero_documento' => '1000873458'])
            ->call('buscar');
    }

    public function test_la_cuenta_de_consulta_ve_que_hacer_y_no_el_error_tecnico(): void
    {
        $this->actingAs(User::factory()->create([
            'roles' => ['CONSULTA'],
            'cuenta_compartida' => true,
            'sede_id' => Sede::factory()->create()->id,
        ]));

        $this->consultar()
            ->assertSee('Savia no respondió')
            ->assertSee('avisa a un administrador')
            ->assertDontSee('cURL')
            ->assertDontSee('intersavia')
            ->assertDontSee('SAVIA_VERIFY_SSL');
    }

    public function test_el_administrador_ve_el_detalle_tecnico(): void
    {
        $this->actingAs(User::factory()->administrador()->create());

        $this->consultar()
            ->assertSee('No se pudo contactar el servicio')
            ->assertSee('cURL error 28');
    }

    public function test_sin_credenciales_quien_consulta_no_ve_los_nombres_de_las_variables(): void
    {
        config(['savia.username' => null, 'savia.password' => null]);
        $this->actingAs(User::factory()->create(['roles' => ['ORIENTADOR'], 'sede_id' => Sede::factory()->create()->id]));

        $this->consultar()
            ->assertSee('Savia no respondió')
            ->assertDontSee('SAVIA_USERNAME');
    }
}
