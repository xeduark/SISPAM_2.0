<?php

namespace Tests\Feature\CuentasCompartidas;

use App\Filament\Pages\ConsultarPaciente;
use App\Filament\Pages\Identificarse;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Services\CuentaCompartida\QuienUsaLaCuenta;
use Database\Seeders\CuentasDeConsultaSeeder;
use Database\Seeders\RolSeeder;
use Database\Seeders\SedeSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Tests\Concerns\RecorreElPanel;
use Tests\TestCase;

/**
 * El rol CONSULTA: consulta pacientes en Savia, ve lo básico y nada más.
 * No registra, no actualiza, no ve la ficha y no entra a ninguna otra parte.
 */
class RolConsultaTest extends TestCase
{
    use FingeSavia, RecorreElPanel, RefreshDatabase;

    private Sede $sede;

    private User $cuenta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolSeeder::class);
        $this->configurarSavia();

        $this->sede = Sede::factory()->create(['nombre' => 'PREMIUM PLAZA', 'codigo' => 'PRP']);
        $this->cuenta = User::factory()->create([
            'documento' => 'ConsultaPRP',
            'nombre' => 'Consulta',
            'apellido' => 'PREMIUM PLAZA',
            'sede_id' => $this->sede->id,
            'roles' => ['CONSULTA'],
            'cuenta_compartida' => true,
        ]);
    }

    /** Entra con la cuenta de la sede y dice quién es, como al iniciar sesión. */
    private function entrarComoConsulta(): void
    {
        $this->actingAs($this->cuenta);
        session([QuienUsaLaCuenta::SESION => ['nombre' => 'Ana Pérez', 'documento' => '43123456']]);
    }

    private function entrarComo(string $rol): User
    {
        $usuario = User::factory()->create(['roles' => [$rol], 'sede_id' => $this->sede->id]);
        $this->actingAs($usuario);

        return $usuario;
    }

    /* ------------------------------------------------------------------ *
     *  El rol
     * ------------------------------------------------------------------ */

    public function test_el_rol_consulta_solo_tiene_ver_lo_basico_de_consultar_paciente(): void
    {
        $this->assertSame(['consultar_paciente' => ['ver']], Rol::where('nombre', 'CONSULTA')->sole()->permisos);
        $this->assertTrue($this->cuenta->soloPuedeConsultar());
    }

    public function test_la_migracion_le_da_ver_todo_a_quien_ya_consultaba_pero_no_a_consulta(): void
    {
        Rol::query()->delete();
        Rol::create(['nombre' => 'ORIENTADOR', 'permisos' => ['consultar_paciente' => ['ver'], 'pacientes' => ['ver']]]);
        Rol::create(['nombre' => 'CONSULTA', 'permisos' => ['consultar_paciente' => ['ver']]]);
        Rol::create(['nombre' => 'FARMACIA', 'permisos' => ['entrega' => ['ver']]]);

        $migracion = require database_path('migrations/2026_10_13_110000_ver_todo_lo_de_savia.php');
        $migracion->up();
        $migracion->up(); // correrla dos veces no duplica

        $this->assertSame(['ver', 'ver_completo'], Rol::where('nombre', 'ORIENTADOR')->sole()->permisos['consultar_paciente']);
        $this->assertSame(['ver'], Rol::where('nombre', 'CONSULTA')->sole()->permisos['consultar_paciente']);
        $this->assertSame(['entrega' => ['ver']], Rol::where('nombre', 'FARMACIA')->sole()->permisos);

        $migracion->down();

        $this->assertSame(['ver'], Rol::where('nombre', 'ORIENTADOR')->sole()->permisos['consultar_paciente']);
    }

    /* ------------------------------------------------------------------ *
     *  Lo que ve: solo Consultar paciente
     * ------------------------------------------------------------------ */

    public function test_en_el_menu_solo_aparece_consultar_paciente(): void
    {
        $this->entrarComoConsulta();

        $this->get(ConsultarPaciente::getUrl())->assertOk();

        $this->assertSame(['Consultar paciente'], $this->menuDelPanel());
    }

    public function test_al_entrar_al_panel_llega_directo_a_consultar_paciente(): void
    {
        $this->entrarComoConsulta();

        $this->get('/admin')->assertRedirect(ConsultarPaciente::getUrl());
        $this->assertSame(ConsultarPaciente::getUrl(), Filament::getPanel('admin')->getUrl());
    }

    public function test_los_demas_siguen_llegando_al_escritorio(): void
    {
        $this->entrarComo('ORIENTADOR');

        $this->get('/admin')->assertOk();
    }

    /**
     * Recorre **todas** las rutas del panel y las de fuera que sirven datos:
     * una pantalla nueva que olvide su permiso hace fallar esta prueba.
     */
    public function test_cualquier_otra_pantalla_le_responde_403(): void
    {
        $this->crearUnRegistroDeCadaTipo($this->sede);
        $this->entrarComoConsulta();

        // Las que sí puede abrir.
        $rutas = $this->rutasDelPanel(menos: [
            ConsultarPaciente::getRouteName(),
            Identificarse::getRouteName(),
            'filament.admin.pages.dashboard', // redirige a Consultar paciente
        ]);

        foreach ($rutas as $ruta) {
            $this->get($this->urlDe($ruta))->assertForbidden();
        }

        foreach ($this->archivosFueraDelPanel() as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    /* ------------------------------------------------------------------ *
     *  Lo que ve de Savia: lo básico
     * ------------------------------------------------------------------ */

    public function test_ve_lo_basico_y_los_datos_de_salud_no_llegan_al_navegador(): void
    {
        $this->entrarComoConsulta();

        $pantalla = $this->consultar()
            ->assertSee('JUAN JOSE RAMIREZ PULGARIN')
            ->assertSee('Afiliación: Activo')
            ->assertSee('CONTRIBUTIVO')
            ->assertSee('E.S.E METROSALUD')
            ->assertSee('Ves los datos básicos')
            ->assertDontSee('PROGRAMA VIH')
            ->assertDontSee('Programas especiales')
            ->assertDontSee('MEDELLÍN')
            ->assertDontSee('3001234567');

        // Ni en el estado de Livewire, que viaja al navegador aunque no se pinte.
        $this->assertSame([], array_diff(array_keys($pantalla->get('afiliado')), ConsultarPaciente::CAMPOS_BASICOS));
    }

    public function test_el_orientador_sigue_viendo_todo(): void
    {
        $this->entrarComo('ORIENTADOR');

        $this->consultar()
            ->assertSee('PROGRAMA VIH')
            ->assertSee('MEDELLÍN')
            ->assertDontSee('Ves los datos básicos');
    }

    /* ------------------------------------------------------------------ *
     *  No registra, no actualiza, no ve la ficha
     * ------------------------------------------------------------------ */

    public function test_no_puede_registrar_ni_llamando_a_guardar_directamente(): void
    {
        $this->entrarComoConsulta();

        $this->consultar()
            ->assertSee('No registrado en SISPAM')
            ->assertDontSee('Registrar en SISPAM')
            ->call('guardar')
            ->assertForbidden();

        $this->assertSame(0, Paciente::count());
    }

    public function test_no_puede_actualizar_ni_llamando_a_guardar_directamente(): void
    {
        $paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1000873458',
            'regimen' => 'SUBSIDIADO',
        ]);
        $this->entrarComoConsulta();

        $this->consultar()
            ->assertSee('Registrado en SISPAM')
            ->assertDontSee('Actualizar en SISPAM')
            ->call('guardar')
            ->assertForbidden();

        $this->assertSame('SUBSIDIADO', $paciente->fresh()->regimen);
    }

    public function test_no_puede_cambiar_desde_el_navegador_lo_que_decide_los_permisos(): void
    {
        $this->entrarComoConsulta();
        $pantalla = $this->consultar();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $pantalla->set('pacienteId', 99);
    }

    public function test_ve_que_esta_registrado_pero_no_el_enlace_a_la_ficha(): void
    {
        Paciente::factory()->create(['tipo_documento' => 'CC', 'numero_documento' => '1000873458']);
        $this->entrarComoConsulta();

        $this->consultar()
            ->assertSee('Registrado en SISPAM')
            ->assertSee('Registrado desde el')
            ->assertDontSee('Ver ficha en SISPAM');
    }

    /* ------------------------------------------------------------------ *
     *  ORIENTADOR y el administrador, igual que antes
     * ------------------------------------------------------------------ */

    public function test_el_orientador_registra_y_actualiza(): void
    {
        $this->entrarComo('ORIENTADOR');

        $this->consultar()
            ->assertSee('Registrar en SISPAM')
            ->call('guardar')
            ->assertHasNoErrors();

        $paciente = Paciente::sole();
        $paciente->update(['regimen' => 'SUBSIDIADO']);

        $this->consultar()
            ->assertSee('Actualizar en SISPAM')
            ->assertSee('Ver ficha en SISPAM')
            ->call('guardar');

        $this->assertSame('CONTRIBUTIVO', $paciente->fresh()->regimen);
    }

    public function test_el_administrador_registra_y_actualiza(): void
    {
        $this->actingAs(User::factory()->administrador()->create());

        $this->consultar()->call('guardar');
        $this->assertSame(1, Paciente::count());

        Paciente::sole()->update(['regimen' => 'SUBSIDIADO']);
        $this->consultar()->assertSee('Actualizar en SISPAM')->call('guardar');

        $this->assertSame('CONTRIBUTIVO', Paciente::sole()->regimen);
    }

    public function test_con_crear_pero_sin_editar_no_actualiza(): void
    {
        Rol::create(['nombre' => 'REGISTRO', 'permisos' => [
            'consultar_paciente' => ['ver', 'ver_completo'],
            'pacientes' => ['ver', 'crear'],
        ]]);
        Paciente::factory()->create(['tipo_documento' => 'CC', 'numero_documento' => '1000873458', 'regimen' => 'SUBSIDIADO']);
        $this->entrarComo('REGISTRO');

        $this->consultar()
            ->assertDontSee('Actualizar en SISPAM')
            ->assertSee('Ver ficha en SISPAM')
            ->call('guardar')
            ->assertForbidden();

        $this->assertSame('SUBSIDIADO', Paciente::sole()->regimen);
    }

    /* ------------------------------------------------------------------ *
     *  Las cuatro cuentas
     * ------------------------------------------------------------------ */

    public function test_el_seeder_crea_las_cuatro_cuentas_y_se_puede_volver_a_correr(): void
    {
        User::query()->delete();
        $this->seed(SedeSeeder::class);

        $this->seed(CuentasDeConsultaSeeder::class);
        User::where('documento', 'ConsultaBIC')->update(['activo' => false]); // decisión del administrador
        $this->seed(CuentasDeConsultaSeeder::class);

        $this->assertSame(4, User::count());

        foreach (CuentasDeConsultaSeeder::CUENTAS as $codigo => $documento) {
            $cuenta = User::where('documento', $documento)->sole();

            $this->assertTrue($cuenta->cuenta_compartida);
            $this->assertFalse($cuenta->es_administrador);
            $this->assertSame($codigo, $cuenta->sede->codigo);
            $this->assertSame([$cuenta->sede_id], $cuenta->sedes()->pluck('sedes.id')->all());
        }

        $this->assertFalse(User::where('documento', 'ConsultaBIC')->sole()->activo);
        $this->assertSame(1, Rol::where('nombre', 'CONSULTA')->count());
    }
}
