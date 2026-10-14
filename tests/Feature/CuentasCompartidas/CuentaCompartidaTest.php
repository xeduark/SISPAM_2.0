<?php

namespace Tests\Feature\CuentasCompartidas;

use App\Filament\Pages\ConsultarPaciente;
use App\Filament\Pages\Identificarse;
use App\Filament\Resources\AuditoriaResource\Pages\ListAuditorias;
use App\Http\Middleware\CerrarCuentaCompartidaInactiva;
use App\Livewire\SelectorDeSede;
use App\Models\Auditoria;
use App\Models\Sede;
use App\Models\User;
use App\Services\CuentaCompartida\QuienUsaLaCuenta;
use Database\Seeders\RolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;
use Mockery;
use SocialiteProviders\Authentik\Provider as AuthentikProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use Tests\TestCase;

/**
 * Una cuenta que usan varias personas de la sede: antes de consultar, la
 * persona dice quién es; cada consulta queda con la cuenta, la sede y esa
 * persona; la cuenta queda fija en su sede y se cierra sola si nadie la usa.
 */
class CuentaCompartidaTest extends TestCase
{
    use FingeSavia, RefreshDatabase;

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
            'roles' => ['CONSULTA'],
            'cuenta_compartida' => true,
        ]);
        $this->cuenta->sedes()->sync([$this->sede->id]);
    }

    private function identificarse(string $nombre = 'Ana Pérez', string $documento = '43123456'): void
    {
        Livewire::test(Identificarse::class)
            ->fillForm(['nombre' => $nombre, 'documento' => $documento])
            ->call('continuar')
            ->assertHasNoFormErrors();
    }

    /* ------------------------------------------------------------------ *
     *  Antes de consultar, quién es
     * ------------------------------------------------------------------ */

    public function test_sin_decir_quien_es_cualquier_pantalla_lleva_a_identificarse(): void
    {
        $this->actingAs($this->cuenta);

        $this->get(ConsultarPaciente::getUrl())->assertRedirect(Identificarse::getUrl());
        $this->get('/admin')->assertRedirect(Identificarse::getUrl());
        $this->get(Identificarse::getUrl())
            ->assertOk()
            ->assertSee('¿Quién está usando esta cuenta?')
            ->assertSee('PREMIUM PLAZA (PRP)');
    }

    public function test_no_puede_consultar_sin_decir_quien_es_ni_llamando_al_metodo(): void
    {
        $this->actingAs($this->cuenta);

        $this->consultar()->assertRedirect(Identificarse::getUrl());

        Http::assertNothingSent();
        $this->assertDatabaseMissing('auditorias', ['accion' => Auditoria::ACCION_CONSULTO_SAVIA]);
    }

    public function test_pide_nombre_y_documento_validos(): void
    {
        $this->actingAs($this->cuenta);

        Livewire::test(Identificarse::class)
            ->fillForm(['nombre' => '', 'documento' => '12.345.678'])
            ->call('continuar')
            ->assertHasFormErrors(['nombre' => 'required', 'documento' => 'regex']);

        $this->assertTrue(QuienUsaLaCuenta::falta());
    }

    public function test_cada_consulta_queda_con_la_cuenta_la_sede_y_la_persona(): void
    {
        $this->actingAs($this->cuenta);
        $this->identificarse();

        $this->get(ConsultarPaciente::getUrl())
            ->assertOk()
            ->assertSee('Las consultas quedan a nombre de Ana Pérez');

        $this->consultar();

        $this->assertDatabaseHas('auditorias', [
            'accion' => Auditoria::ACCION_SE_IDENTIFICO,
            'usuario_documento' => 'ConsultaPRP',
            'persona_nombre' => 'Ana Pérez',
            'persona_documento' => '43123456',
        ]);

        $consulta = Auditoria::where('accion', Auditoria::ACCION_CONSULTO_SAVIA)->sole();
        $this->assertSame($this->cuenta->id, $consulta->usuario_id);
        $this->assertSame('ConsultaPRP', $consulta->usuario_documento);
        $this->assertSame($this->sede->id, $consulta->sede_id);
        $this->assertSame('Ana Pérez', $consulta->persona_nombre);
        $this->assertSame('43123456', $consulta->persona_documento);
    }

    public function test_cambiar_de_persona_vuelve_a_preguntar(): void
    {
        $this->actingAs($this->cuenta);
        $this->identificarse();

        Livewire::test(ConsultarPaciente::class)
            ->callAction('cambiarDePersona')
            ->assertRedirect(Identificarse::getUrl());

        $this->assertTrue(QuienUsaLaCuenta::falta());

        $this->identificarse('Luis Gómez', '71222333');
        $this->consultar();

        $this->assertSame('Luis Gómez', Auditoria::where('accion', Auditoria::ACCION_CONSULTO_SAVIA)->sole()->persona_nombre);
    }

    public function test_al_iniciar_sesion_se_vuelve_a_preguntar(): void
    {
        session([QuienUsaLaCuenta::SESION => ['nombre' => 'Persona anterior', 'documento' => '11111']]);

        $this->authentikDevuelve('ConsultaPRP', ['CONSULTA']);
        $this->get(route('auth.authentik.callback'))->assertRedirect();

        $this->assertAuthenticatedAs($this->cuenta);
        $this->assertTrue(QuienUsaLaCuenta::falta());

        // La línea de ingreso no sale a nombre de quien estaba antes.
        $this->assertNull(Auditoria::where('accion', Auditoria::ACCION_INGRESO)->sole()->persona_nombre);
    }

    public function test_las_cuentas_personales_no_se_identifican(): void
    {
        $orientador = User::factory()->create(['roles' => ['ORIENTADOR'], 'sede_id' => $this->sede->id]);
        $this->actingAs($orientador);

        $this->get(ConsultarPaciente::getUrl())->assertOk()->assertDontSee('quedan a nombre de');
        $this->get(Identificarse::getUrl())->assertForbidden();

        $this->consultar();
        $this->assertNull(Auditoria::where('accion', Auditoria::ACCION_CONSULTO_SAVIA)->sole()->persona_documento);
    }

    /* ------------------------------------------------------------------ *
     *  Varias personas a la vez
     * ------------------------------------------------------------------ */

    public function test_dos_personas_usan_la_misma_cuenta_a_la_vez_y_cada_una_queda_con_su_nombre(): void
    {
        // Equipo 1
        $this->actingAs($this->cuenta);
        $this->identificarse('Ana Pérez', '43123456');
        $this->consultar();
        $sesionDelEquipo1 = session()->all();

        // Equipo 2: otra sesión, misma cuenta.
        $this->flushSession();
        $this->actingAs($this->cuenta);
        $this->identificarse('Luis Gómez', '71222333');
        $this->consultar();

        // El equipo 1 sigue con su sesión y su persona.
        $this->flushSession();
        session($sesionDelEquipo1);
        $this->actingAs($this->cuenta);
        $this->consultar();

        $this->assertSame(
            ['Ana Pérez', 'Luis Gómez', 'Ana Pérez'],
            Auditoria::where('accion', Auditoria::ACCION_CONSULTO_SAVIA)->orderBy('id')->pluck('persona_nombre')->all(),
        );
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
        session([QuienUsaLaCuenta::SESION => ['nombre' => 'Ana Pérez', 'documento' => '43123456']]);

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
        $this->identificarse();

        $this->get(ConsultarPaciente::getUrl())->assertOk()->assertSee('sispam-cierre-por-inactividad');

        $this->travel(29)->minutes();
        $this->get(ConsultarPaciente::getUrl())->assertOk(); // usarla reinicia el reloj

        $this->travel(29)->minutes();
        $this->get(ConsultarPaciente::getUrl())->assertOk();

        $this->travel(31)->minutes();
        $this->get(ConsultarPaciente::getUrl())
            ->assertRedirect('https://auth.example.com/application/o/sispam/end-session/');

        $this->assertGuest();
        $this->assertNull(session(QuienUsaLaCuenta::SESION));

        $cierre = Auditoria::where('accion', Auditoria::ACCION_CERRO_POR_INACTIVIDAD)->sole();
        $this->assertSame('Ana Pérez', $cierre->persona_nombre);
        $this->assertDatabaseMissing('auditorias', ['accion' => Auditoria::ACCION_CERRO_SESION]);
    }

    public function test_a_una_peticion_de_livewire_vencida_le_responde_que_la_pagina_expiro(): void
    {
        $this->actingAs($this->cuenta);
        $this->identificarse();
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
        $this->identificarse();

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

    public function test_sin_decir_quien_es_livewire_responde_403(): void
    {
        $this->actingAs($this->cuenta);
        $this->identificarse();
        $snapshot = $this->snapshotDe($this->get(ConsultarPaciente::getUrl())->getContent(), 'app.filament.pages.consultar-paciente');

        QuienUsaLaCuenta::olvidar();

        $this->peticionDeLivewire($snapshot, 'limpiar')
            ->assertForbidden()
            ->assertJson(['message' => 'Antes de continuar, di quién está usando la cuenta.']);
    }

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

    public function test_el_cierre_de_la_pantalla_queda_como_inactividad(): void
    {
        $this->actingAs($this->cuenta);
        $this->identificarse();

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

    public function test_la_auditoria_muestra_la_persona_bajo_la_cuenta(): void
    {
        $this->actingAs($this->cuenta);
        $this->identificarse();
        $this->consultar();

        $otro = Auditoria::factory()->create(['accion' => Auditoria::ACCION_CONSULTO_SAVIA]);

        $this->actingAs(User::factory()->administrador()->create());

        Livewire::test(ListAuditorias::class)
            ->assertSee('ConsultaPRP · cuenta compartida')
            ->assertSee('Persona: Ana Pérez · 43123456')
            ->searchTable('43123456')
            ->assertCanNotSeeTableRecords([$otro])
            ->searchTable(null)
            ->filterTable('cuenta_compartida')
            ->assertCanSeeTableRecords(Auditoria::where('usuario_id', $this->cuenta->id)->get())
            ->assertCanNotSeeTableRecords([$otro]);
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
