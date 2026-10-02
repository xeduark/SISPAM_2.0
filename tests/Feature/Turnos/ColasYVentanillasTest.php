<?php

namespace Tests\Feature\Turnos;

use App\Filament\Resources\ColaResource\Pages\CreateCola;
use App\Filament\Resources\ColaResource\Pages\ListColas;
use App\Filament\Resources\SedeResource\Pages\ListSedes;
use App\Filament\Resources\VentanillaResource\Pages\ListVentanillas;
use App\Models\Auditoria;
use App\Models\Cola;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Models\Ventanilla;
use App\Services\Turnos\GeneradorDeTurnos;
use Database\Seeders\ColaSeeder;
use Filament\Tables\Actions\DeleteAction as TableDeleteAction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Colas y ventanillas por sede, y el consecutivo diario de los turnos.
 */
class ColasYVentanillasTest extends TestCase
{
    use RefreshDatabase;

    private function generador(): GeneradorDeTurnos
    {
        return app(GeneradorDeTurnos::class);
    }

    /** Un usuario que administra colas en su sede. */
    private function encargadoDe(Sede $sede): User
    {
        Rol::firstOrCreate(['nombre' => 'COORDINADOR'], [
            'permisos' => ['colas' => ['ver', 'crear', 'editar', 'eliminar']],
        ]);

        return User::factory()->create(['sede_id' => $sede->id, 'roles' => ['COORDINADOR']]);
    }

    /* ------------------------------------------------------------------ *
     *  El turno
     * ------------------------------------------------------------------ */

    public function test_el_turno_lleva_el_prefijo_de_la_cola_y_arranca_en_uno(): void
    {
        $cola = Cola::factory()->create(['prefijo' => 'A']);

        $this->assertSame('A-001', $this->generador()->siguiente($cola));
        $this->assertSame('A-002', $this->generador()->siguiente($cola));
        $this->assertSame('A-003', $this->generador()->siguiente($cola));
    }

    public function test_cada_cola_lleva_su_propio_consecutivo(): void
    {
        $sede = Sede::factory()->create();
        $general = Cola::factory()->create(['sede_id' => $sede->id, 'prefijo' => 'A']);
        $altoCosto = Cola::factory()->create(['sede_id' => $sede->id, 'prefijo' => 'B']);

        $this->generador()->siguiente($general);
        $this->generador()->siguiente($general);

        // La otra cola no heredó el consecutivo.
        $this->assertSame('B-001', $this->generador()->siguiente($altoCosto));
        $this->assertSame('A-003', $this->generador()->siguiente($general));
    }

    public function test_las_colas_de_sedes_distintas_no_se_pisan(): void
    {
        $la30 = Cola::factory()->create(['sede_id' => Sede::factory()->create(['nombre' => 'La 30']), 'prefijo' => 'A']);
        $bic = Cola::factory()->create(['sede_id' => Sede::factory()->create(['nombre' => 'BIC']), 'prefijo' => 'A']);

        $this->generador()->siguiente($la30);
        $this->generador()->siguiente($la30);

        $this->assertSame('A-001', $this->generador()->siguiente($bic));
    }

    public function test_el_consecutivo_se_reinicia_cada_dia(): void
    {
        $cola = Cola::factory()->create(['prefijo' => 'A']);

        $this->generador()->siguiente($cola, now()->subDay());
        $this->generador()->siguiente($cola, now()->subDay());

        // Hoy vuelve a empezar.
        $this->assertSame('A-001', $this->generador()->siguiente($cola));

        // Y el de ayer sigue donde iba.
        $this->assertSame('A-003', $this->generador()->siguiente($cola, now()->subDay()));
    }

    public function test_el_turno_se_rellena_con_ceros_hasta_tres_cifras(): void
    {
        $cola = Cola::factory()->create(['prefijo' => 'AC']);

        $this->assertSame('AC-001', $cola->formatearTurno(1));
        $this->assertSame('AC-042', $cola->formatearTurno(42));
        $this->assertSame('AC-999', $cola->formatearTurno(999));
        // Pasado el 999 sigue creciendo, no se corta.
        $this->assertSame('AC-1000', $cola->formatearTurno(1000));
    }

    public function test_pedir_muchos_turnos_no_repite_ninguno(): void
    {
        $cola = Cola::factory()->create(['prefijo' => 'A']);

        $turnos = [];
        for ($i = 0; $i < 50; $i++) {
            $turnos[] = $this->generador()->siguiente($cola);
        }

        $this->assertCount(50, array_unique($turnos));
        $this->assertSame('A-050', end($turnos));
        $this->assertSame(50, $this->generador()->entregadosHoy($cola));
    }

    public function test_entregados_hoy_no_consume_turno(): void
    {
        $cola = Cola::factory()->create();

        $this->assertSame(0, $this->generador()->entregadosHoy($cola));

        $this->generador()->siguiente($cola);

        $this->assertSame(1, $this->generador()->entregadosHoy($cola));
        $this->assertSame(1, $this->generador()->entregadosHoy($cola));
    }

    /* ------------------------------------------------------------------ *
     *  Todo separado por sede
     * ------------------------------------------------------------------ */

    public function test_cada_quien_ve_solo_las_colas_de_su_sede(): void
    {
        $la30 = Sede::factory()->create(['nombre' => 'La 30']);
        $bic = Sede::factory()->create(['nombre' => 'BIC']);

        $deLa30 = Cola::factory()->create(['sede_id' => $la30->id, 'nombre' => 'General La 30']);
        $deBic = Cola::factory()->create(['sede_id' => $bic->id, 'nombre' => 'General BIC']);

        $this->actingAs($this->encargadoDe($la30));

        Livewire::test(ListColas::class)
            ->assertCanSeeTableRecords([$deLa30])
            ->assertCanNotSeeTableRecords([$deBic]);
    }

    public function test_el_administrador_ve_las_colas_de_todas_las_sedes(): void
    {
        $deLa30 = Cola::factory()->create(['sede_id' => Sede::factory()->create(['nombre' => 'La 30'])]);
        $deBic = Cola::factory()->create(['sede_id' => Sede::factory()->create(['nombre' => 'BIC'])]);

        $this->actingAs(User::factory()->administrador()->create());

        Livewire::test(ListColas::class)
            ->assertCanSeeTableRecords([$deLa30, $deBic]);
    }

    public function test_cada_quien_ve_solo_las_ventanillas_de_su_sede(): void
    {
        $la30 = Sede::factory()->create(['nombre' => 'La 30']);
        $bic = Sede::factory()->create(['nombre' => 'BIC']);

        $deLa30 = Ventanilla::factory()->create(['sede_id' => $la30->id]);
        $deBic = Ventanilla::factory()->create(['sede_id' => $bic->id]);

        $this->actingAs($this->encargadoDe($la30));

        Livewire::test(ListVentanillas::class)
            ->assertCanSeeTableRecords([$deLa30])
            ->assertCanNotSeeTableRecords([$deBic]);
    }

    public function test_al_crear_una_cola_queda_en_la_sede_del_usuario(): void
    {
        $la30 = Sede::factory()->create(['nombre' => 'La 30']);
        $this->actingAs($this->encargadoDe($la30));

        Livewire::test(CreateCola::class)
            ->fillForm(['nombre' => 'Dispensación general', 'prefijo' => 'a', 'orden' => 1])
            ->call('create')
            ->assertHasNoFormErrors();

        $cola = Cola::where('nombre', 'Dispensación general')->firstOrFail();

        $this->assertSame($la30->id, $cola->sede_id);
        // El prefijo se guarda en mayúsculas.
        $this->assertSame('A', $cola->prefijo);
        $this->assertSame('A-001', $cola->formatearTurno(1));
    }

    /* ------------------------------------------------------------------ *
     *  Reglas de negocio
     * ------------------------------------------------------------------ */

    public function test_no_se_repite_el_prefijo_dentro_de_la_misma_sede(): void
    {
        $la30 = Sede::factory()->create();
        Cola::factory()->create(['sede_id' => $la30->id, 'prefijo' => 'A']);

        $this->actingAs($this->encargadoDe($la30));

        $this->expectException(UniqueConstraintViolationException::class);

        Cola::create(['sede_id' => $la30->id, 'nombre' => 'Otra', 'prefijo' => 'A']);
    }

    public function test_una_cola_que_ya_entrego_turnos_no_se_elimina(): void
    {
        $la30 = Sede::factory()->create();
        $cola = Cola::factory()->create(['sede_id' => $la30->id]);

        $this->generador()->siguiente($cola);

        $this->actingAs($this->encargadoDe($la30));

        Livewire::test(ListColas::class)
            ->callTableAction(TableDeleteAction::class, $cola)
            ->assertNotified('No se puede eliminar');

        $this->assertModelExists($cola);
    }

    public function test_una_cola_sin_turnos_si_se_elimina(): void
    {
        $la30 = Sede::factory()->create();
        $cola = Cola::factory()->create(['sede_id' => $la30->id]);

        $this->actingAs($this->encargadoDe($la30));

        Livewire::test(ListColas::class)
            ->callTableAction(TableDeleteAction::class, $cola);

        $this->assertModelMissing($cola);
    }

    public function test_no_se_elimina_una_sede_que_tiene_colas(): void
    {
        $sede = Sede::factory()->create();
        Cola::factory()->create(['sede_id' => $sede->id]);

        $this->actingAs(User::factory()->administrador()->create());

        Livewire::test(ListSedes::class)
            ->callTableAction(TableDeleteAction::class, $sede)
            ->assertNotified('No se puede eliminar');

        $this->assertModelExists($sede);
    }

    /* ------------------------------------------------------------------ *
     *  Permisos y auditoría
     * ------------------------------------------------------------------ */

    public function test_sin_el_permiso_no_se_entra(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/colas')->assertForbidden();
        $this->get('/admin/ventanillas')->assertForbidden();
    }

    public function test_con_el_permiso_se_entra(): void
    {
        $this->actingAs($this->encargadoDe(Sede::factory()->create()));

        $this->get('/admin/colas')->assertOk();
        $this->get('/admin/ventanillas')->assertOk();
    }

    public function test_los_cambios_en_las_colas_quedan_en_la_auditoria(): void
    {
        $sede = Sede::factory()->create(['nombre' => 'La 30']);
        $this->actingAs($this->encargadoDe($sede));

        // Con `activa` explícito, como llega siempre desde el formulario.
        $cola = Cola::factory()->create(['sede_id' => $sede->id, 'nombre' => 'General', 'prefijo' => 'A']);
        Auditoria::query()->delete();

        $cola->update(['activa' => false]);

        $registro = Auditoria::where('entidad_tipo', 'cola')->firstOrFail();

        $this->assertSame('Actualizó la cola «General» de La 30', $registro->descripcion);
        $this->assertSame(['Sí', 'No'], $registro->cambios['activa']);
    }

    /* ------------------------------------------------------------------ *
     *  El seeder
     * ------------------------------------------------------------------ */

    public function test_el_seeder_crea_dos_colas_y_dos_ventanillas_por_sede(): void
    {
        Sede::factory()->create(['nombre' => 'La 30']);
        Sede::factory()->create(['nombre' => 'BIC']);

        $this->seed(ColaSeeder::class);

        foreach (['La 30', 'BIC'] as $nombre) {
            $sede = Sede::where('nombre', $nombre)->firstOrFail();

            $this->assertSame(2, $sede->colas()->count());
            $this->assertSame(2, $sede->ventanillas()->count());
            $this->assertSame(['A', 'B'], $sede->colas()->orderBy('orden')->pluck('prefijo')->all());
        }
    }

    public function test_el_seeder_se_puede_volver_a_correr_sin_duplicar(): void
    {
        Sede::factory()->create(['nombre' => 'La 30']);

        $this->seed(ColaSeeder::class);
        $this->seed(ColaSeeder::class);

        $this->assertSame(2, Cola::count());
        $this->assertSame(2, Ventanilla::count());
    }
}
