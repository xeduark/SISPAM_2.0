<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_la_pagina_de_login_pide_documento_y_no_email(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Documento de identidad')
            ->assertSeeHtml('autocomplete="username"')
            ->assertDontSeeHtml('type="email"');
    }

    public function test_un_usuario_activo_ingresa_con_documento_y_contrasena(): void
    {
        $user = User::factory()->create(['documento' => '1000000000', 'password' => 'Sispam2026*']);

        Livewire::test(Login::class)
            ->fillForm(['documento' => '1000000000', 'password' => 'Sispam2026*'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
    }

    public function test_credenciales_invalidas_muestran_error_en_espanol(): void
    {
        User::factory()->create(['documento' => '1000000000', 'password' => 'Sispam2026*']);

        Livewire::test(Login::class)
            ->fillForm(['documento' => '1000000000', 'password' => 'incorrecta'])
            ->call('authenticate')
            ->assertHasFormErrors(['documento'])
            ->assertSee('Las credenciales no coinciden con nuestros registros.');

        $this->assertGuest();
    }

    public function test_el_email_no_sirve_como_credencial(): void
    {
        User::factory()->create(['email' => 'admin@sispam.com', 'password' => 'Sispam2026*']);

        Livewire::test(Login::class)
            ->fillForm(['documento' => 'admin@sispam.com', 'password' => 'Sispam2026*'])
            ->call('authenticate')
            ->assertHasFormErrors(['documento']);

        $this->assertGuest();
    }

    public function test_un_usuario_inactivo_no_puede_ingresar(): void
    {
        User::factory()->inactivo()->create(['documento' => '2000000000', 'password' => 'Sispam2026*']);

        Livewire::test(Login::class)
            ->fillForm(['documento' => '2000000000', 'password' => 'Sispam2026*'])
            ->call('authenticate')
            ->assertHasFormErrors(['documento']);

        $this->assertGuest();
    }

    public function test_un_usuario_desactivado_con_sesion_abierta_pierde_acceso_al_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertOk();

        $user->update(['activo' => false]);

        $this->actingAs($user->fresh())->get('/admin')->assertForbidden();
    }
}
