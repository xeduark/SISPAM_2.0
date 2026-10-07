<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use SocialiteProviders\Authentik\Provider as AuthentikProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.authentik.base_url' => 'https://auth.example.com',
            'services.authentik.client_id' => 'sispam',
            'services.authentik.client_secret' => 'secreto',
            'services.authentik.redirect' => 'http://localhost/auth/authentik/callback',
            'services.authentik.app_slug' => 'sispam',
        ]);
    }

    /**
     * Simula la respuesta de Authentik en el callback.
     */
    private function authentikDevuelve(?string $preferredUsername, array $grupos = []): void
    {
        $socialiteUser = (new SocialiteUser)->setRaw([
            'sub' => 'abc123',
            'preferred_username' => $preferredUsername,
            'email' => 'persona@example.com',
            'groups' => $grupos,
        ]);

        $provider = Mockery::mock(AuthentikProvider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);
    }

    public function test_la_pagina_de_login_solo_muestra_el_boton_de_authentik(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Iniciar sesión')
            ->assertSee(route('auth.authentik.redirect'), escape: false)
            ->assertDontSeeHtml('type="password"')
            ->assertDontSee('Documento de identidad');
    }

    public function test_el_boton_redirige_a_authentik(): void
    {
        $response = $this->get(route('auth.authentik.redirect'));

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://auth.example.com/application/o/authorize/',
            $response->headers->get('Location'),
        );
    }

    public function test_sin_configuracion_de_authentik_vuelve_al_login_con_aviso(): void
    {
        config(['services.authentik.base_url' => null]);

        $this->get(route('auth.authentik.redirect'))->assertRedirect('/admin/login');

        $this->assertStringContainsString('no está configurado', json_encode(session('filament.notifications'), JSON_UNESCAPED_UNICODE));
    }

    public function test_un_usuario_registrado_y_activo_ingresa(): void
    {
        $user = User::factory()->create(['documento' => '1000000000']);
        $this->authentikDevuelve('1000000000');

        $this->get(route('auth.authentik.callback'))->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
    }

    public function test_los_roles_se_toman_de_los_grupos_de_authentik_en_cada_ingreso(): void
    {
        $user = User::factory()->create(['documento' => '1000000000', 'roles' => ['VIEJO']]);
        $this->authentikDevuelve('1000000000', ['ORIENTADOR']);

        $this->get(route('auth.authentik.callback'))->assertRedirect('/admin');

        $this->assertSame(['ORIENTADOR'], $user->fresh()->roles);
    }

    public function test_un_usuario_que_no_existe_en_sispam_es_rechazado(): void
    {
        User::factory()->create(['documento' => '1000000000']);
        $this->authentikDevuelve('9999999999');

        $this->get(route('auth.authentik.callback'))->assertRedirect('/admin/login');

        $this->assertGuest();
        $this->assertStringContainsString('no está registrado en SISPAM', json_encode(session('filament.notifications'), JSON_UNESCAPED_UNICODE));
    }

    public function test_un_usuario_inactivo_es_rechazado(): void
    {
        User::factory()->inactivo()->create(['documento' => '2000000000']);
        $this->authentikDevuelve('2000000000');

        $this->get(route('auth.authentik.callback'))->assertRedirect('/admin/login');

        $this->assertGuest();
        $this->assertStringContainsString('inactivo', json_encode(session('filament.notifications'), JSON_UNESCAPED_UNICODE));
    }

    public function test_un_error_de_authentik_no_autentica(): void
    {
        $provider = Mockery::mock(AuthentikProvider::class);
        $provider->shouldReceive('user')->andThrow(new Exception('state inválido'));
        Socialite::shouldReceive('driver')->with('authentik')->andReturn($provider);

        $this->get(route('auth.authentik.callback'))->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    public function test_cerrar_sesion_tambien_cierra_la_sesion_en_authentik(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('filament.admin.auth.logout'))
            ->assertRedirect('https://auth.example.com/application/o/sispam/end-session/');

        $this->assertGuest();
    }

    public function test_sin_slug_configurado_cerrar_sesion_vuelve_al_login(): void
    {
        config(['services.authentik.app_slug' => null]);

        $this->actingAs(User::factory()->create())
            ->post(route('filament.admin.auth.logout'))
            ->assertRedirect('/admin/login');
    }

    public function test_un_usuario_desactivado_con_sesion_abierta_pierde_acceso_al_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertOk();

        $user->update(['activo' => false]);

        $this->actingAs($user->fresh())->get('/admin')->assertForbidden();
    }
}
