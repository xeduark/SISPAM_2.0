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
use Illuminate\Support\Facades\Hash;
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

    public function test_crea_un_usuario_con_la_contrasena_encriptada(): void
    {
        $sede = Sede::factory()->create();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'nombre' => 'Ana',
                'apellido' => 'Pérez',
                'documento' => '52123456',
                'email' => 'ana@example.com',
                'sede_id' => $sede->id,
                'password' => 'Secreta123*',
                'activo' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('documento', '52123456')->firstOrFail();
        $this->assertTrue(Hash::check('Secreta123*', $user->password));
        $this->assertSame('Ana Pérez', $user->nombre_completo);
    }

    public function test_valida_campos_obligatorios_y_documento_unico(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'nombre' => '',
                'documento' => $this->admin->documento,
                'password' => '',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'nombre' => 'required',
                'apellido' => 'required',
                'documento' => 'unique',
                'sede_id' => 'required',
                'password' => 'required',
            ]);
    }

    public function test_editar_sin_contrasena_conserva_la_actual(): void
    {
        $user = User::factory()->create(['password' => 'Original123*']);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['nombre' => 'Cambiado', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertSame('Cambiado', $user->nombre);
        $this->assertTrue(Hash::check('Original123*', $user->password));
    }

    public function test_editar_permite_conservar_su_propio_documento_y_email(): void
    {
        $user = User::factory()->create();

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['documento' => $user->documento, 'email' => $user->email])
            ->call('save')
            ->assertHasNoFormErrors();
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
