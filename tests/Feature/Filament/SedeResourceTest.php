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

        $this->actingAs(User::factory()->administrador()->create());
    }

    public function test_crea_una_sede(): void
    {
        Livewire::test(CreateSede::class)
            ->fillForm([
                'nombre' => 'Sede Norte',
                'codigo' => 'NOR',
                'direccion' => 'Calle 1 # 2-3',
                'telefono' => '6011234567',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('sedes', ['nombre' => 'Sede Norte', 'codigo' => 'NOR', 'activa' => true]);
    }

    /* ------------------------------------------------------------------ *
     *  El código: tres caracteres, obligatorio y único
     * ------------------------------------------------------------------ */

    public function test_sin_codigo_no_se_crea_la_sede(): void
    {
        Livewire::test(CreateSede::class)
            ->fillForm(['nombre' => 'Sede Norte'])
            ->call('create')
            ->assertHasFormErrors(['codigo']);

        $this->assertDatabaseMissing('sedes', ['nombre' => 'Sede Norte']);
    }

    /**
     * Tres caracteres exactos: es lo que cabe en el ticket impreso de 80 mm.
     */
    public function test_el_codigo_no_puede_tener_mas_ni_menos_de_tres(): void
    {
        foreach (['NO', 'NORTE', 'N Ó'] as $invalido) {
            Livewire::test(CreateSede::class)
                ->fillForm(['nombre' => 'Sede Norte', 'codigo' => $invalido])
                ->call('create')
                ->assertHasFormErrors(['codigo']);
        }

        $this->assertDatabaseMissing('sedes', ['nombre' => 'Sede Norte']);
    }

    /** Se escribe como se quiera; se guarda en mayúsculas. */
    public function test_el_codigo_se_guarda_en_mayusculas(): void
    {
        Livewire::test(CreateSede::class)
            ->fillForm(['nombre' => 'Sede Norte', 'codigo' => 'nor'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('sedes', ['nombre' => 'Sede Norte', 'codigo' => 'NOR']);
    }

    public function test_el_codigo_no_se_repite_entre_sedes(): void
    {
        Sede::factory()->create(['nombre' => 'PREMIUM PLAZA', 'codigo' => 'PRP']);

        Livewire::test(CreateSede::class)
            ->fillForm(['nombre' => 'Otra sede', 'codigo' => 'PRP'])
            ->call('create')
            ->assertHasFormErrors(['codigo']);
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
