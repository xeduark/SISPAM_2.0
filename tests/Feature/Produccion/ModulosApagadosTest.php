<?php

namespace Tests\Feature\Produccion;

use App\Filament\Pages\ConsultarPaciente;
use App\Filament\Resources\RolResource\Pages\ManageRoles;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use Database\Seeders\RolSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RecorreElPanel;
use Tests\Feature\CuentasCompartidas\FingeSavia;
use Tests\TestCase;

/**
 * La primera salida a producción: solo consulta, usuarios, sedes y auditoría
 * (`SISPAM_MODULOS`). Lo demás no existe en esa instalación, ni para el
 * administrador, para que nadie cree datos reales con lo que todavía no sale.
 */
class ModulosApagadosTest extends TestCase
{
    use FingeSavia, RecorreElPanel, RefreshDatabase;

    private const ENCENDIDOS = ['consultar_paciente', 'usuarios', 'sedes', 'auditoria'];

    /** Lo que el administrador sí abre con esos módulos encendidos. */
    private const PANTALLAS_ENCENDIDAS = [
        'filament.admin.pages.dashboard',
        'filament.admin.pages.consultar-paciente',
        'filament.admin.resources.users.',
        'filament.admin.resources.sedes.',
        'filament.admin.resources.auditorias.',
        'filament.admin.resources.roles.', // la matriz es del administrador, no un módulo
    ];

    private Sede $sede;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sispam.modulos' => self::ENCENDIDOS]);
        $this->seed(RolSeeder::class);
        $this->configurarSavia();

        $this->sede = Sede::factory()->create(['nombre' => 'PREMIUM PLAZA', 'codigo' => 'PRP']);
    }

    private function entrarComoAdministrador(): User
    {
        $administrador = User::factory()->administrador()->create(['sede_id' => $this->sede->id]);
        $this->actingAs($administrador);

        return $administrador;
    }

    public function test_el_administrador_solo_ve_en_el_menu_lo_encendido(): void
    {
        $this->entrarComoAdministrador();

        $this->get('/admin')->assertOk();

        $this->assertEqualsCanonicalizing(
            ['Escritorio', 'Consultar paciente', 'Usuarios', 'Sedes', 'Auditoría', 'Roles y permisos'],
            $this->menuDelPanel(),
        );
    }

    public function test_lo_apagado_le_responde_403_tambien_al_administrador(): void
    {
        $this->crearUnRegistroDeCadaTipo($this->sede);
        $this->entrarComoAdministrador();

        foreach ($this->rutasDelPanel() as $ruta) {
            $encendida = collect(self::PANTALLAS_ENCENDIDAS)
                ->contains(fn (string $prefijo): bool => str_starts_with((string) $ruta->getName(), $prefijo));

            $respuesta = $this->get($this->urlDe($ruta));

            $encendida
                ? $respuesta->assertOk()
                : $respuesta->assertForbidden();
        }

        foreach ($this->archivosFueraDelPanel() as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_la_sala_publica_no_existe_con_el_turnero_apagado(): void
    {
        $this->get('/sala/PRP')->assertNotFound();
        $this->get('/sala/PRP/turnos')->assertNotFound();

        config(['sispam.modulos' => []]);

        $this->get('/sala/PRP')->assertOk();
    }

    public function test_con_la_lista_vacia_todo_queda_encendido(): void
    {
        config(['sispam.modulos' => []]);
        $this->entrarComoAdministrador();

        $this->get('/admin/pacientes')->assertOk();
        $this->get('/admin/tickets')->assertOk();
        $this->assertTrue(Rol::moduloEncendido('transcripcion'));
    }

    public function test_el_administrador_consulta_y_ve_todo_pero_no_registra_pacientes(): void
    {
        $this->entrarComoAdministrador();

        $this->consultar()
            ->assertSee('PROGRAMA VIH')
            ->assertDontSee('Registrar en SISPAM')
            ->call('guardar')
            ->assertForbidden();
    }

    public function test_las_cuentas_de_consulta_siguen_igual(): void
    {
        $this->actingAs(User::factory()->create([
            'roles' => ['CONSULTA'],
            'cuenta_compartida' => true,
            'sede_id' => $this->sede->id,
        ]));

        $this->get('/admin')->assertRedirect(ConsultarPaciente::getUrl());
        $this->consultar()->assertSee('JUAN JOSE RAMIREZ PULGARIN');
        $this->assertSame(['Consultar paciente'], $this->menuDelPanel());
    }

    public function test_quien_solo_puede_usar_lo_encendido_de_consulta_llega_directo_a_consultar(): void
    {
        // Un orientador en esta instalación: lo demás de su rol está apagado.
        $this->actingAs(User::factory()->create(['roles' => ['ORIENTADOR'], 'sede_id' => $this->sede->id]));

        $this->get('/admin')->assertRedirect(ConsultarPaciente::getUrl());
    }

    public function test_la_matriz_marca_los_modulos_apagados_sin_borrarles_los_permisos(): void
    {
        $this->entrarComoAdministrador();
        $orientador = Rol::where('nombre', 'ORIENTADOR')->sole();

        Livewire::test(ManageRoles::class)
            ->mountTableAction('edit', $orientador)
            ->assertSee('Orientación (orden médica y alto costo) — apagado en esta instalación')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        // Guardar el rol no le quitó lo que tiene en módulos apagados.
        $this->assertSame(['usar', 'ver_orden'], $orientador->fresh()->permisos['orientacion']);
    }

    public function test_el_cierre_del_dia_no_corre_con_los_tickets_apagados(): void
    {
        $cierre = collect(app(Schedule::class)->events())
            ->first(fn ($evento): bool => str_contains((string) $evento->command, 'tickets:cerrar-dia'));

        $this->assertNotNull($cierre);
        $this->assertFalse($cierre->filtersPass($this->app));

        config(['sispam.modulos' => []]);
        $this->assertTrue($cierre->filtersPass($this->app));
    }
}
