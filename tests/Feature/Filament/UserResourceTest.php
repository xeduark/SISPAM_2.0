<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Sede;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteAction as TableDeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_lista_los_usuarios(): void
    {
        $otros = User::factory()->count(3)->create();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords($otros->push($this->admin));
    }

    public function test_crea_un_usuario_sin_contrasena_local(): void
    {
        $sede = Sede::factory()->create();

        Livewire::test(CreateUser::class)
            ->assertFormFieldDoesNotExist('password')
            ->fillForm([
                'nombre' => 'Ana',
                'apellido' => 'Pérez',
                'documento' => '52123456',
                'email' => 'ana@example.com',
                'sede_id' => $sede->id,
                'activo' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('documento', '52123456')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertSame('Ana Pérez', $user->nombre_completo);
    }

    public function test_valida_campos_obligatorios_y_documento_unico(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'nombre' => '',
                'documento' => $this->admin->documento,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'nombre' => 'required',
                'apellido' => 'required',
                'documento' => 'unique',
                'sede_id' => 'required',
            ]);
    }

    public function test_edita_un_usuario(): void
    {
        $user = User::factory()->create();

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['nombre' => 'Cambiado'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Cambiado', $user->refresh()->nombre);
    }

    public function test_editar_permite_conservar_su_propio_documento_y_email(): void
    {
        $user = User::factory()->create();

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['documento' => $user->documento, 'email' => $user->email])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_el_documento_acepta_usernames_alfanumericos_de_authentik(): void
    {
        $user = User::factory()->create(['documento' => 'AdminSispam']);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['nombre' => 'Cambiado'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['documento' => 'admin sispam!'])
            ->call('save')
            ->assertHasFormErrors(['documento' => 'alpha_num']);
    }

    public function test_un_usuario_no_puede_eliminarse_a_si_mismo(): void
    {
        $otro = User::factory()->create();

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden(TableDeleteAction::class, $this->admin)
            ->assertTableActionVisible(TableDeleteAction::class, $otro)
            // Simula una petición forzada al servidor: una acción oculta no se puede montar
            ->call('mountTableAction', 'delete', (string) $this->admin->getKey())
            ->call('callMountedTableAction');

        Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
            ->assertActionHidden(DeleteAction::class);

        $this->assertModelExists($this->admin);
    }

    public function test_puede_eliminar_a_otro_usuario(): void
    {
        $otro = User::factory()->create();

        Livewire::test(ListUsers::class)
            ->callTableAction(TableDeleteAction::class, $otro);

        $this->assertModelMissing($otro);
    }

    public function test_la_eliminacion_masiva_se_cancela_si_incluye_al_usuario_actual(): void
    {
        $otro = User::factory()->create();

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('delete', [$this->admin, $otro])
            ->assertNotified('No puedes eliminar tu propio usuario');

        $this->assertModelExists($this->admin);
        $this->assertModelExists($otro);
    }
}
