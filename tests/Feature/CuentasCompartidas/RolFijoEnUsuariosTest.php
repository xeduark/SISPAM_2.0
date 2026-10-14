<?php

namespace Tests\Feature\CuentasCompartidas;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use Database\Seeders\RolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\FingeAuthentik;
use Tests\TestCase;

/**
 * En Usuarios, el rol de una cuenta compartida queda fijo en CONSULTA, y al
 * guardarla SISPAM le asigna ese grupo en Authentik, como con cualquier rol.
 */
class RolFijoEnUsuariosTest extends TestCase
{
    use FingeAuthentik, RefreshDatabase;

    private Sede $sede;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolSeeder::class);
        $this->sede = Sede::factory()->create(['nombre' => 'PREMIUM PLAZA', 'codigo' => 'PRP']);
        $this->actingAs(User::factory()->administrador()->create(['documento' => 'AdminSispam']));

        $this->configurarAuthentik();
    }

    public function test_el_campo_roles_queda_fijo_en_consulta_y_no_se_puede_cambiar(): void
    {
        $cuenta = User::factory()->create(['documento' => 'ConsultaPRP', 'sede_id' => $this->sede->id, 'cuenta_compartida' => true]);
        $this->cuentaEnAuthentik('ConsultaPRP', [$this->grupoEnAuthentik(Rol::CONSULTA)]);

        Livewire::test(EditUser::class, ['record' => $cuenta->getRouteKey()])
            ->assertFormSet(['roles' => [Rol::CONSULTA]])
            ->assertFormFieldIsDisabled('roles')
            ->assertSee('Siempre tiene el rol CONSULTA y solo ese')
            ->assertDontSee('Pide nombre y documento')
            // Aunque alguien mande otro rol saltándose el formulario.
            ->set('data.roles', ['ORIENTADOR'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([Rol::CONSULTA], $cuenta->fresh()->roles);
    }

    public function test_al_guardar_una_que_no_tenia_el_rol_se_le_asigna_el_grupo_en_authentik(): void
    {
        // Como llegaron a producción: sin rol, y sin el grupo allá.
        $cuenta = User::factory()->create(['documento' => 'ConsultaPRP', 'sede_id' => $this->sede->id]);
        $cuenta->forceFill(['cuenta_compartida' => true, 'roles' => []])->saveQuietly();
        $otraAplicacion = $this->grupoEnAuthentik('documenta-usuarios');
        $consulta = $this->grupoEnAuthentik(Rol::CONSULTA);
        $pk = $this->cuentaEnAuthentik('ConsultaPRP', [$otraAplicacion]);

        Livewire::test(EditUser::class, ['record' => $cuenta->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([Rol::CONSULTA], $cuenta->fresh()->roles);
        $this->assertEqualsCanonicalizing([$otraAplicacion, $consulta], $this->cuentasAuthentik[$pk]['groups']);
    }

    public function test_marcar_compartida_al_crear_la_deja_con_consulta_en_sispam_y_en_authentik(): void
    {
        $consulta = $this->grupoEnAuthentik(Rol::CONSULTA);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'nombre' => 'Consulta',
                'apellido' => 'AVENTURA',
                'documento' => 'ConsultaAVT',
                'clave_authentik' => 'Clave-Segura-2026',
                'sede_id' => $this->sede->id,
                'activo' => true,
                'roles' => ['ORIENTADOR'],
            ])
            ->set('data.cuenta_compartida', true)
            ->assertFormSet(['roles' => [Rol::CONSULTA]])
            ->call('create')
            ->assertHasNoFormErrors();

        $cuenta = User::where('documento', 'ConsultaAVT')->sole();
        $this->assertTrue($cuenta->cuenta_compartida);
        $this->assertSame([Rol::CONSULTA], $cuenta->roles);
        $this->assertSame([$consulta], $this->cuentaAuthentik('ConsultaAVT')['groups']);
    }

    public function test_si_tenia_otros_grupos_en_authentik_no_se_suman_a_sus_roles(): void
    {
        $orientador = $this->grupoEnAuthentik('ORIENTADOR');
        $this->grupoEnAuthentik(Rol::CONSULTA);
        $this->cuentaEnAuthentik('ConsultaBIC', [$orientador]);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'nombre' => 'Consulta',
                'apellido' => 'EDIFICIO BIC',
                'documento' => 'ConsultaBIC',
                'clave_authentik' => 'Clave-Segura-2026',
                'sede_id' => $this->sede->id,
                'activo' => true,
                'cuenta_compartida' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cuenta = User::where('documento', 'ConsultaBIC')->sole();
        $this->assertSame([Rol::CONSULTA], $cuenta->roles);
        $this->assertFalse($cuenta->puede('orientacion.usar'));
    }
}
