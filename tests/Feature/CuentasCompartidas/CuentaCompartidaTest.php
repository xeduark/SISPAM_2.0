<?php

namespace Tests\Feature\CuentasCompartidas;

use App\Filament\Pages\ConsultarPaciente;
use App\Filament\Resources\AuditoriaResource\Pages\ListAuditorias;
use App\Http\Middleware\CerrarCuentaCompartidaInactiva;
use App\Livewire\SelectorDeSede;
use App\Models\Auditoria;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use Database\Seeders\RolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;
use Mockery;
use SocialiteProviders\Authentik\Provider as AuthentikProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use Tests\Concerns\RecorreElPanel;
use Tests\TestCase;

/**
 * Una cuenta que usan varias personas de la sede: entra, consulta y nada más.
 *
 * Siempre tiene el rol CONSULTA y solo ese, venga lo que venga de Authentik;
 * cada consulta queda con la cuenta y la sede; queda fija en su sede y se
 * cierra sola si nadie la usa. Desde la v1.0.1 ya no pide nombre ni documento.
 */
class CuentaCompartidaTest extends TestCase
{
    use FingeSavia, RecorreElPanel, RefreshDatabase;

    private Sede $sede;

    private User $cuenta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolSeeder::class);
        $this->configurarSavia();

        config([
            'services.authentik.base_url' => 'https://auth.example.com',
            'services.authentik.client_id' => 'sispam',
            'services.authentik.client_secret' => 'secreto',
            'services.authentik.redirect' => 'http://localhost/auth/authentik/callback',
            'services.authentik.app_slug' => 'sispam',
        ]);

        $this->sede = Sede::factory()->create(['nombre' => 'PREMIUM PLAZA', 'codigo' => 'PRP']);
        $this->cuenta = User::factory()->create([
            'documento' => 'ConsultaPRP',
            'nombre' => 'Consulta',
            'apellido' => 'PREMIUM PLAZA',
            'sede_id' => $this->sede->id,
            'cuenta_compartida' => true,
        ]);
        $this->cuenta->sedes()->sync([$this->sede->id]);
    }

    /* ------------------------------------------------------------------ *
     *  Entrar y consultar, nada más
     * ------------------------------------------------------------------ */

    public function test_al_iniciar_sesion_llega_directo_a_consultar_paciente(): void
    {
        $this->authentikDevuelve('ConsultaPRP', ['CONSULTA']);

        $this->get(route('auth.authentik.callback'))->assertRedirect(ConsultarPaciente::getUrl());
        $this->assertAuthenticatedAs($this->cuenta);

        $this->get(ConsultarPaciente::getUrl())
            ->assertOk()
            ->assertSee('Buscar afiliado')
            ->assertDontSee('¿Quién está usando esta cuenta?');

        $this->assertSame(['Consultar paciente'], $this->menuDelPanel());
    }

    public function test_aunque_antes_hubiera_abierto_otra_direccion_entra_a_consultar(): void
    {
        session(['url.intended' => url('/admin/auditorias')]);
        $this->authentikDevuelve('ConsultaPRP', ['CONSULTA']);

        $this->get(route('auth.authentik.callback'))->assertRedirect(ConsultarPaciente::getUrl());
    }

    public function test_ya_no_pide_nombre_ni_documento(): void
    {
        $this->actingAs($this->cuenta);

        $this->get('/admin/identificarse')->assertNotFound();
        $this->get('/admin')->assertRedirect(ConsultarPaciente::getUrl());

        $this->consultar()->assertSee('JUAN JOSE RAMIREZ PULGARIN');
    }

    public function test_cada_consulta_queda_con_la_cuenta_y_la_sede(): void
    {
        $this->actingAs($this->cuenta);

        $this->consultar();

        $consulta = Auditoria::where('accion', Auditoria::ACCION_CONSULTO_SAVIA)->sole();
        $this->assertSame($this->cuenta->id, $consulta->usuario_id);
        $this->assertSame('ConsultaPRP', $consulta->usuario_documento);
        $this->assertSame($this->sede->id, $consulta->sede_id);
        $this->assertNull($consulta->persona_documento);
    }

    public function test_el_escritorio_no_es_para_ella_aunque_consulta_tenga_mas_permisos(): void
    {
        // El administrador le da algo más al rol en la matriz.
        Rol::where('nombre', Rol::CONSULTA)->sole()->update(['permisos' => [
            'consultar_paciente' => ['ver'],
            'auditoria' => ['ver'],
        ]]);
        $this->actingAs($this->cuenta);

        $this->get('/admin')->assertRedirect(ConsultarPaciente::getUrl());
        $this->get('/admin/auditorias')->assertOk();
        $this->assertNotContains('Escritorio', $this->menuDelPanel());
    }

    /* ------------------------------------------------------------------ *
     *  Siempre CONSULTA, y solo CONSULTA
     * ------------------------------------------------------------------ */

    public function test_sin_el_grupo_en_authentik_igual_entra_con_consulta(): void
    {
        $this->authentikDevuelve('ConsultaPRP', []);

        $this->get(route('auth.authentik.callback'))->assertRedirect(ConsultarPaciente::getUrl());

        $this->assertSame([Rol::CONSULTA], $this->cuenta->fresh()->roles);
        $this->get(ConsultarPaciente::getUrl())->assertOk();
    }

    public function test_otro_grupo_en_authentik_no_le_da_mas_permisos(): void
    {
        $this->authentikDevuelve('ConsultaPRP', ['CONSULTA', 'ORIENTADOR', 'authentik Admins']);

        $this->get(route('auth.authentik.callback'));

        $cuenta = $this->cuenta->fresh();
        $this->assertSame([Rol::CONSULTA], $cuenta->roles);
        $this->assertFalse($cuenta->puede('pacientes.crear'));
        $this->assertFalse($cuenta->puede('orientacion.usar'));
        $this->get('/admin/pacientes')->assertForbidden();
    }

    public function test_aunque_la_fila_diga_otra_cosa_solo_cuenta_consulta(): void
    {
        // Alguien tocó la base a mano.
        DB::table('users')->where('id', $this->cuenta->id)->update(['roles' => json_encode(['ORIENTADOR'])]);

        $cuenta = $this->cuenta->fresh();

        $this->assertSame([Rol::CONSULTA], $cuenta->rolesEfectivos());
        $this->assertTrue($cuenta->puede('consultar_paciente.ver'));
        $this->assertFalse($cuenta->puede('pacientes.crear'));
    }

    public function test_lo_que_puede_hacer_se_ajusta_en_la_matriz(): void
    {
        // Con lo del rol: lo básico, los programas y el teléfono, sin la dirección.
        $this->actingAs($this->cuenta);
        $this->consultar()->assertSee('PROGRAMA VIH')->assertSee('3001234567')->assertDontSee('KR 40 70A 23');

        Rol::where('nombre', Rol::CONSULTA)->sole()->update(['permisos' => [
            'consultar_paciente' => ['ver'],
        ]]);
        $this->actingAs($this->cuenta->fresh());

        $this->consultar()->assertDontSee('PROGRAMA VIH')->assertDontSee('3001234567');

        Rol::where('nombre', Rol::CONSULTA)->sole()->update(['permisos' => [
            'consultar_paciente' => ['ver', 'ver_completo'],
        ]]);
        $this->actingAs($this->cuenta->fresh());

        $this->consultar()->assertSee('PROGRAMA VIH')->assertSee('3001234567')->assertSee('KR 40 70A 23');
    }

    public function test_marcarla_como_compartida_le_deja_solo_consulta(): void
    {
        $persona = User::factory()->create(['roles' => ['ORIENTADOR', 'DISPENSADOR'], 'sede_id' => $this->sede->id]);

        $persona->update(['cuenta_compartida' => true]);

        $this->assertSame([Rol::CONSULTA], $persona->fresh()->roles);
    }

    /* ------------------------------------------------------------------ *
     *  Fija en su sede
     * ------------------------------------------------------------------ */

    public function test_no_ve_el_selector_de_sede_ni_puede_moverse(): void
    {
        $otra = Sede::factory()->create(['codigo' => 'BIC']);
        // Aunque un administrador le asigne otra sede, sigue fija en la suya.
        $this->cuenta->sedes()->attach($otra->id);

        $this->actingAs($this->cuenta);

        $this->assertFalse($this->cuenta->puedeCambiarDeSede());
        $this->get(ConsultarPaciente::getUrl())->assertOk()->assertDontSee('sispam-selector-sede');

        Livewire::test(SelectorDeSede::class)->call('cambiar', $otra->id);

        $this->assertSame($this->sede->id, $this->cuenta->fresh()->sede_id);
    }

    public function test_una_cuenta_compartida_nunca_es_administradora(): void
    {
        $this->cuenta->update(['es_administrador' => true]);

        $this->assertFalse($this->cuenta->fresh()->es_administrador);
    }

    /* ------------------------------------------------------------------ *
     *  Se cierra sola tras 30 minutos sin uso
     * ------------------------------------------------------------------ */

    public function test_tras_30_minutos_sin_uso_se_cierra_tambien_en_authentik(): void
    {
        $this->actingAs($this->cuenta);

        $this->get(ConsultarPaciente::getUrl())->assertOk()->assertSee('sispam-cierre-por-inactividad');

        $this->travel(29)->minutes();
        $this->get(ConsultarPaciente::getUrl())->assertOk(); // usarla reinicia el reloj

        $this->travel(29)->minutes();
        $this->get(ConsultarPaciente::getUrl())->assertOk();

        $this->travel(31)->minutes();
        $this->get(ConsultarPaciente::getUrl())
            ->assertRedirect('https://auth.example.com/application/o/sispam/end-session/');

        $this->assertGuest();

        $cierre = Auditoria::where('accion', Auditoria::ACCION_CERRO_POR_INACTIVIDAD)->sole();
        $this->assertSame('ConsultaPRP', $cierre->usuario_documento);
        $this->assertDatabaseMissing('auditorias', ['accion' => Auditoria::ACCION_CERRO_SESION]);
    }

    public function test_a_una_peticion_de_livewire_vencida_le_responde_que_la_pagina_expiro(): void
    {
        $this->actingAs($this->cuenta);
        $this->get(ConsultarPaciente::getUrl())->assertOk();

        $this->travel(31)->minutes();

        // El navegador recarga con el 419 y esa recarga cierra todo.
        $this->withHeader('X-Livewire', 'true')->get(ConsultarPaciente::getUrl())->assertStatus(419);
        $this->assertAuthenticatedAs($this->cuenta);
    }

    /**
     * Con la petición real a `/livewire/update`, no con `Livewire::test()`:
     * así se comprueba que el reloj corre también ahí. Si no, alguien que
     * consulta sin recargar la página vería cerrarse la sesión en plena consulta.
     */
    public function test_consultar_sin_recargar_la_pagina_cuenta_como_uso(): void
    {
        $this->actingAs($this->cuenta);

        $snapshot = $this->snapshotDe($this->get(ConsultarPaciente::getUrl())->getContent(), 'app.filament.pages.consultar-paciente');

        $this->travel(20)->minutes();
        $this->peticionDeLivewire($snapshot, 'limpiar')->assertOk();

        // 40 minutos desde que se abrió la página, 20 desde la última petición.
        $this->travel(20)->minutes();
        $this->get(ConsultarPaciente::getUrl())->assertOk();
        $this->assertAuthenticatedAs($this->cuenta);

        $this->travel(31)->minutes();
        $this->peticionDeLivewire($snapshot, 'limpiar')->assertStatus(419);
    }

    public function test_el_cierre_de_la_pantalla_queda_como_inactividad(): void
    {
        $this->actingAs($this->cuenta);

        $this->post(filament()->getLogoutUrl(), ['motivo' => CerrarCuentaCompartidaInactiva::MOTIVO])
            ->assertRedirect('https://auth.example.com/application/o/sispam/end-session/');

        $this->assertGuest();
        $this->assertSame(1, Auditoria::where('accion', Auditoria::ACCION_CERRO_POR_INACTIVIDAD)->count());
    }

    public function test_las_cuentas_personales_no_se_cierran_a_los_30_minutos(): void
    {
        $orientador = User::factory()->create(['roles' => ['ORIENTADOR'], 'sede_id' => $this->sede->id]);
        $this->actingAs($orientador);

        $this->get(ConsultarPaciente::getUrl())->assertOk()->assertDontSee('sispam-cierre-por-inactividad');

        $this->travel(90)->minutes();
        $this->get(ConsultarPaciente::getUrl())->assertOk();
        $this->assertAuthenticatedAs($orientador);
    }

    /* ------------------------------------------------------------------ *
     *  Administración → Auditoría
     * ------------------------------------------------------------------ */

    public function test_la_auditoria_marca_la_cuenta_compartida(): void
    {
        $this->actingAs($this->cuenta);
        $this->consultar();
        $otro = Auditoria::factory()->create(['accion' => Auditoria::ACCION_CONSULTO_SAVIA]);

        $this->actingAs(User::factory()->administrador()->create());

        Livewire::test(ListAuditorias::class)
            ->assertSee('ConsultaPRP · cuenta compartida')
            ->filterTable('cuenta_compartida')
            ->assertCanSeeTableRecords(Auditoria::where('usuario_id', $this->cuenta->id)->get())
            ->assertCanNotSeeTableRecords([$otro]);
    }

    public function test_las_auditorias_viejas_con_persona_se_siguen_viendo(): void
    {
        // Lo que dejó la v1.0.0, cuando se pedía nombre y documento.
        $vieja = Auditoria::factory()->create([
            'usuario_id' => $this->cuenta->id,
            'usuario_nombre' => 'Consulta PREMIUM PLAZA',
            'usuario_documento' => 'ConsultaPRP',
            'persona_nombre' => 'Ana Pérez',
            'persona_documento' => '43123456',
            'accion' => Auditoria::ACCION_SE_IDENTIFICO,
        ]);

        $this->actingAs(User::factory()->administrador()->create());

        Livewire::test(ListAuditorias::class)
            ->assertSee('Persona: Ana Pérez · 43123456')
            ->assertSee('Se identificó')
            ->searchTable('43123456')
            ->assertCanSeeTableRecords([$vieja]);

        $this->get("/admin/auditorias/{$vieja->id}")
            ->assertOk()
            ->assertSee('Persona que usaba la cuenta')
            ->assertSee('Ana Pérez');
    }

    /* ------------------------------------------------------------------ *
     *  Ayudas
     * ------------------------------------------------------------------ */

    private function snapshotDe(string $html, string $componente): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $encontrados);

        foreach ($encontrados[1] as $crudo) {
            $snapshot = html_entity_decode($crudo, ENT_QUOTES);

            if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $componente) {
                return $snapshot;
            }
        }

        $this->fail("La página no trae el componente {$componente}.");
    }

    private function peticionDeLivewire(string $snapshot, string $metodo): TestResponse
    {
        return $this->withHeader('X-Livewire', 'true')->postJson('/livewire/update', [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['path' => '', 'method' => $metodo, 'params' => []]],
            ]],
        ]);
    }

    /**
     * Simula la respuesta de Authentik en el callback (como en `LoginTest`).
     *
     * @param  list<string>  $grupos
     */
    private function authentikDevuelve(string $usuario, array $grupos): void
    {
        $socialiteUser = (new SocialiteUser)->setRaw([
            'sub' => 'abc123',
            'preferred_username' => $usuario,
            'groups' => $grupos,
        ]);

        $provider = Mockery::mock(AuthentikProvider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);
    }
}
