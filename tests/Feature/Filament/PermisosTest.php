<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\RolResource\Pages\ManageRoles;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Matriz de permisos por módulo: los roles vienen de Authentik y el
 * administrador ve todo.
 */
class PermisosTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_permisos_no_entra_a_ningun_modulo(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/pacientes')->assertForbidden();
        $this->get('/admin/consultar-paciente')->assertForbidden();
        $this->get('/admin/sedes')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/roles')->assertForbidden();
    }

    public function test_cada_accion_depende_de_la_matriz(): void
    {
        Rol::create(['nombre' => 'CONSULTA', 'permisos' => ['pacientes' => ['ver']]]);
        $this->actingAs(User::factory()->create(['roles' => ['CONSULTA']]));

        $this->get('/admin/pacientes')->assertOk();
        $this->get('/admin/pacientes/create')->assertForbidden();
    }

    public function test_los_permisos_de_varios_roles_se_suman(): void
    {
        Rol::create(['nombre' => 'A', 'permisos' => ['pacientes' => ['ver']]]);
        Rol::create(['nombre' => 'B', 'permisos' => ['pacientes' => ['crear'], 'sedes' => ['ver']]]);
        $usuario = User::factory()->create(['roles' => ['A', 'B']]);

        $this->assertTrue($usuario->puede('pacientes.ver'));
        $this->assertTrue($usuario->puede('pacientes.crear'));
        $this->assertTrue($usuario->puede('sedes.ver'));
        $this->assertFalse($usuario->puede('pacientes.eliminar'));
    }

    public function test_el_administrador_ve_todo_sin_roles(): void
    {
        $this->actingAs(User::factory()->administrador()->create());

        foreach (['pacientes', 'pacientes/create', 'consultar-paciente', 'sedes', 'users', 'roles'] as $ruta) {
            $this->get("/admin/{$ruta}")->assertOk();
        }
    }

    public function test_el_administrador_arma_la_matriz_de_un_rol(): void
    {
        $this->actingAs(User::factory()->administrador()->create());

        Livewire::test(ManageRoles::class)
            ->callAction('create', [
                'nombre' => ' auxiliar ',
                'permisos' => ['pacientes' => ['ver', 'editar'], 'orientacion' => ['usar']],
            ])
            ->assertHasNoActionErrors();

        $rol = Rol::sole();
        $this->assertSame('AUXILIAR', $rol->nombre);
        $this->assertEqualsCanonicalizing(['ver', 'editar'], $rol->permisos['pacientes']);
        $this->assertSame(['usar'], $rol->permisos['orientacion']);
    }

    public function test_solo_un_administrador_puede_nombrar_administradores(): void
    {
        Rol::create(['nombre' => 'TALENTO', 'permisos' => ['usuarios' => ['ver', 'editar']]]);
        $this->actingAs(User::factory()->create(['roles' => ['TALENTO']]));

        Livewire::test(EditUser::class, ['record' => User::factory()->create()->getKey()])
            ->assertFormFieldIsHidden('es_administrador');
    }
}
