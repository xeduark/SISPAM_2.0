<?php

namespace Tests\Feature\CuentasCompartidas;

use App\Filament\Pages\ConsultarPaciente;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use Database\Seeders\CuentasDeConsultaSeeder;
use Database\Seeders\RolSeeder;
use Database\Seeders\SedeSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_el_rol_consulta_solo_tiene_lo_basico_y_los_programas_de_consultar_paciente(): void
    {
        $this->assertSame(['consultar_paciente' => ['ver', 'ver_programas']], Rol::where('nombre', 'CONSULTA')->sole()->permisos);
        $this->assertTrue($this->cuenta->soloPuedeConsultar());
        $this->assertFalse($this->cuenta->puede('consultar_paciente.ver_completo'));
    }

    public function test_la_migracion_le_da_ver_programas_a_consulta_y_a_nadie_mas(): void
    {
        Rol::query()->delete();
        // El administrador ya le había sumado algo a CONSULTA: no se pierde.
        Rol::create(['nombre' => 'CONSULTA', 'permisos' => ['consultar_paciente' => ['ver'], 'auditoria' => ['ver']]]);
        Rol::create(['nombre' => 'ORIENTADOR', 'permisos' => ['consultar_paciente' => ['ver', 'ver_completo'], 'pacientes' => ['ver']]]);
        Rol::create(['nombre' => 'FARMACIA', 'permisos' => ['entrega' => ['ver']]]);

        $migracion = require database_path('migrations/2026_10_16_100000_ver_programas_para_consulta.php');
        $migracion->up();

        $despues = Rol::orderBy('nombre')->pluck('permisos', 'nombre')->all();

        $this->assertSame(['consultar_paciente' => ['ver', 'ver_programas'], 'auditoria' => ['ver']], $despues['CONSULTA']);
        $this->assertSame(['consultar_paciente' => ['ver', 'ver_completo'], 'pacientes' => ['ver']], $despues['ORIENTADOR']);
        $this->assertSame(['entrega' => ['ver']], $despues['FARMACIA']);

        // Correrla dos veces no cambia nada.
        $migracion->up();
        $this->assertSame($despues, Rol::orderBy('nombre')->pluck('permisos', 'nombre')->all());

        $migracion->down();

        $this->assertSame(['consultar_paciente' => ['ver'], 'auditoria' => ['ver']], Rol::where('nombre', 'CONSULTA')->sole()->permisos);
    }

    public function test_la_migracion_no_crea_el_rol_ni_le_da_programas_a_quien_no_consulta(): void
    {
        Rol::query()->delete();

        $migracion = require database_path('migrations/2026_10_16_100000_ver_programas_para_consulta.php');
        $migracion->up();
        $this->assertSame(0, Rol::count());

        // Si el administrador le quitó «ver» a CONSULTA, no se le da lo de encima.
        Rol::create(['nombre' => 'CONSULTA', 'permisos' => ['auditoria' => ['ver']]]);
        $migracion->up();

        $this->assertSame(['auditoria' => ['ver']], Rol::where('nombre', 'CONSULTA')->sole()->permisos);
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

        // Sin el recuadro de Filament (versión, documentación, GitHub).
        $this->get('/admin')
            ->assertOk()
            ->assertDontSee('filamentphp.com/docs', escape: false)
            ->assertDontSee('github.com/filamentphp', escape: false);
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
     *  Lo que ve de Savia: lo básico, y los programas y RIAS
     * ------------------------------------------------------------------ */

    public function test_ve_lo_basico_y_los_demas_datos_de_salud_no_llegan_al_navegador(): void
    {
        $this->entrarComoConsulta();

        $pantalla = $this->consultar()
            ->assertSee('JUAN JOSE RAMIREZ PULGARIN')
            ->assertSee('Afiliación: Activo')
            ->assertSee('CONTRIBUTIVO')
            ->assertSee('E.S.E METROSALUD')
            ->assertSee('Ves los datos básicos')
            ->assertDontSee('MEDELLÍN')
            ->assertDontSee('3001234567');

        // Ni en el estado de Livewire, que viaja al navegador aunque no se pinte.
        $afiliado = $pantalla->get('afiliado');
        $this->assertSame([], array_diff(array_keys($afiliado), [...ConsultarPaciente::CAMPOS_BASICOS, 'programasEspeciales']));

        foreach (['descripcionCiudadResidencia', 'telefonoMovil', 'discapacidad', 'nivelSisben', 'programas'] as $campo) {
            $this->assertArrayNotHasKey($campo, $afiliado);
        }

        // Municipio y categoría sí son de lo básico.
        $this->assertSame('BELLO', $afiliado['municipioAfiliacion']);
        $this->assertSame('B', $afiliado['categoriaIBC']);
    }

    public function test_ve_los_programas_especiales_y_rias_debajo_del_encabezado(): void
    {
        $this->entrarComoConsulta();

        $pantalla = $this->consultar()
            ->assertSee('PROGRAMA VIH')
            ->assertSee('1 programa(s) asociados al afiliado.')
            ->assertSee('También ves los programas especiales y RIAS.')
            ->assertDontSee('Savia no reporta programas');

        $this->assertSame(
            [['tipo' => 'Programa Especial', 'descripcion' => 'PROGRAMA VIH']],
            $pantalla->get('afiliado')['programasEspeciales'],
        );

        // Justo después del encabezado y antes de los grupos de datos.
        $this->assertMatchesRegularExpression(
            '/No registrado en SISPAM.*Ves los datos básicos.*Programas especiales y RIAS.*PROGRAMA VIH.*Identificación del afiliado/s',
            $pantalla->html(stripInitialData: true),
        );
    }

    public function test_si_savia_no_manda_programas_lo_dice_en_vez_de_esconder_la_seccion(): void
    {
        $this->saviaResponde(['programas' => null]);
        $this->entrarComoConsulta();

        $this->consultar()
            ->assertSee('Programas especiales y RIAS')
            ->assertSee('Savia no reporta programas ni RIAS para este afiliado.')
            ->assertDontSee('programa(s) asociados');
    }

    public function test_un_rol_con_solo_ver_no_ve_los_programas_ni_le_llegan_al_navegador(): void
    {
        Rol::create(['nombre' => 'SOLO_LO_BASICO', 'permisos' => ['consultar_paciente' => ['ver']]]);
        $this->entrarComo('SOLO_LO_BASICO');

        $pantalla = $this->consultar()
            ->assertSee('JUAN JOSE RAMIREZ PULGARIN')
            ->assertSee('Ves los datos básicos')
            ->assertDontSee('PROGRAMA VIH')
            ->assertDontSee('Programas especiales y RIAS')
            ->assertDontSee('Savia no reporta programas')
            ->assertDontSee('También ves los programas');

        $afiliado = $pantalla->get('afiliado');
        $this->assertSame([], array_diff(array_keys($afiliado), ConsultarPaciente::CAMPOS_BASICOS));
        $this->assertArrayNotHasKey('programasEspeciales', $afiliado);
        $this->assertArrayNotHasKey('programas', $afiliado);
    }

    public function test_el_administrador_le_quita_los_programas_desde_la_matriz(): void
    {
        Rol::where('nombre', Rol::CONSULTA)->sole()->update(['permisos' => ['consultar_paciente' => ['ver']]]);
        $this->entrarComoConsulta();

        $pantalla = $this->consultar()->assertDontSee('PROGRAMA VIH');

        $this->assertArrayNotHasKey('programasEspeciales', $pantalla->get('afiliado'));
    }

    /* ------------------------------------------------------------------ *
     *  Municipio y categoría, arriba con el nombre
     * ------------------------------------------------------------------ */

    public function test_ve_el_municipio_y_la_categoria_de_un_contributivo_en_el_encabezado(): void
    {
        $this->entrarComoConsulta();

        $pantalla = $this->consultar()
            ->assertSee('Ves los datos básicos de la afiliación: identificación, estado, régimen (con la categoría, si es contributivo), municipio e IPS primaria.');

        // En el encabezado: con el nombre y junto a la insignia del régimen.
        $this->assertMatchesRegularExpression(
            '/JUAN JOSE RAMIREZ PULGARIN.*Municipio: BELLO \(ANTIOQUIA\).*CONTRIBUTIVO.*Categoría B.*No registrado en SISPAM/s',
            $pantalla->html(stripInitialData: true),
        );
    }

    public function test_si_savia_ya_manda_la_palabra_categoria_no_la_repite(): void
    {
        $this->saviaResponde(['categoriaIBC' => 'CATEGORÍA C']);
        $this->entrarComoConsulta();

        $this->consultar()
            ->assertSee('CATEGORÍA C')
            ->assertDontSee('Categoría CATEGORÍA');
    }

    public function test_un_subsidiado_no_muestra_nada_de_categoria(): void
    {
        // Aunque Savia la mande: la categoría solo es del contributivo.
        $this->saviaResponde(['regimen' => 'SUBSIDIADO', 'categoriaIBC' => 'A']);
        $this->entrarComoConsulta();

        $pantalla = $this->consultar()
            ->assertSee('SUBSIDIADO')
            ->assertSee('Municipio: BELLO (ANTIOQUIA)')
            ->assertDontSee('Categoría A')
            ->assertDontSee('Categoría IBC')
            ->assertDontSee('Categoría: sin dato en Savia');

        $this->assertArrayNotHasKey('categoriaIBC', $pantalla->get('afiliado'));
    }

    public function test_un_contributivo_sin_categoria_dice_que_no_hay_dato(): void
    {
        $this->saviaResponde(['regimen' => 'Contributivo', 'categoriaIBC' => null]);
        $this->entrarComoConsulta();

        $this->consultar()
            ->assertSee('Categoría: sin dato en Savia')
            ->assertSee('Municipio: BELLO (ANTIOQUIA)');
    }

    public function test_sin_municipio_usa_la_ciudad_de_afiliacion(): void
    {
        $this->saviaResponde(['municipioAfiliacion' => '', 'ciudadAfiliacion' => 'ENVIGADO']);
        $this->entrarComoConsulta();

        $this->consultar()->assertSee('Municipio: ENVIGADO (ANTIOQUIA)');
    }

    public function test_sin_municipio_ni_ciudad_dice_que_no_hay_dato(): void
    {
        $this->saviaResponde(['municipioAfiliacion' => null, 'departamentoAfiliacion' => null]);
        $this->entrarComoConsulta();

        $this->consultar()->assertSee('Municipio: sin dato en Savia');
    }

    public function test_puede_consultar_con_pt(): void
    {
        $this->saviaResponde(['tipoDocumentoAfiliado' => 'PT', 'documentoAfiliado' => '6543210']);
        $this->entrarComoConsulta();

        $this->consultar('6543210', 'PT')
            ->assertHasNoFormErrors()
            ->assertSet('afiliado.tipoDocumentoAfiliado', 'PT')
            ->assertSee('JUAN JOSE RAMIREZ PULGARIN')
            ->assertSee('Municipio: BELLO (ANTIOQUIA)');

        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), 'consultar-afiliado')
            && $peticion['tipoDocumento'] === 'PT'
            && $peticion['numeroDocumento'] === '6543210');
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
