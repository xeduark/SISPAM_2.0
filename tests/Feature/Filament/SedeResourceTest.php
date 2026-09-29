<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\SedeResource\Pages\CreateSede;
use App\Filament\Resources\SedeResource\Pages\EditSede;
use App\Filament\Resources\SedeResource\Pages\ListSedes;
use App\Models\Sede;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteAction as TableDeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SedeResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_crea_una_sede(): void
    {
        Livewire::test(CreateSede::class)
            ->fillForm(['nombre' => 'Sede Norte', 'direccion' => 'Calle 1 # 2-3', 'telefono' => '6011234567'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('sedes', ['nombre' => 'Sede Norte', 'activa' => true]);
    }

    public function test_no_elimina_una_sede_con_usuarios(): void
    {
        $sede = Sede::factory()->has(User::factory())->create();

        Livewire::test(ListSedes::class)
            ->callTableAction(TableDeleteAction::class, $sede)
            ->assertNotified();

        Livewire::test(EditSede::class, ['record' => $sede->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified();

        Livewire::test(ListSedes::class)
            ->callTableBulkAction('delete', [$sede])
            ->assertNotified();

        $this->assertModelExists($sede);
    }

    public function test_elimina_una_sede_sin_usuarios(): void
    {
        $sede = Sede::factory()->create();

        Livewire::test(ListSedes::class)
            ->callTableAction(TableDeleteAction::class, $sede);

        $this->assertModelMissing($sede);
    }
}
