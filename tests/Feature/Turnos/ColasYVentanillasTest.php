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
use App\Models\Ticket;
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

    public function test_el_turno_es_solo_el_consecutivo_y_arranca_en_uno(): void
    {
        $sede = Sede::factory()->create();

        $this->assertSame('0001', $this->generador()->siguiente($sede));
        $this->assertSame('0002', $this->generador()->siguiente($sede));
        $this->assertSame('0003', $this->generador()->siguiente($sede));
    }

    /**
     * Quien numera es la sede, no la cola.
     *
     * Mientras el turno llevaba prefijo, que cada cola contara aparte estaba
     * bien: `A-0060` y `B-0060` eran distintos. Sin prefijo dejan de serlo, y
     * entrega busca el turno dentro de la sede: encontraría dos.
     */
    public function test_todas_las_colas_de_una_sede_comparten_el_consecutivo(): void
    {
        $sede = Sede::factory()->create();
        Cola::factory()->create(['sede_id' => $sede->id, 'prefijo' => 'A']);
        Cola::factory()->create(['sede_id' => $sede->id, 'prefijo' => 'B']);

        $this->assertSame('0001', $this->generador()->siguiente($sede));
        $this->assertSame('0002', $this->generador()->siguiente($sede));
        $this->assertSame('0003', $this->generador()->siguiente($sede));
    }

    public function test_las_sedes_distintas_no_se_pisan(): void
    {
        $la30 = Sede::factory()->create(['nombre' => 'LA 30']);
        $bic = Sede::factory()->create(['nombre' => 'EDIFICIO BIC']);

        $this->generador()->siguiente($la30);
        $this->generador()->siguiente($la30);

        $this->assertSame('0001', $this->generador()->siguiente($bic));
    }

    public function test_el_consecutivo_se_reinicia_cada_dia(): void
    {
        $sede = Sede::factory()->create();

        $this->generador()->siguiente($sede, now()->subDay());
        $this->generador()->siguiente($sede, now()->subDay());

        // Hoy vuelve a empezar.
        $this->assertSame('0001', $this->generador()->siguiente($sede));

        // Y el de ayer sigue donde iba.
        $this->assertSame('0003', $this->generador()->siguiente($sede, now()->subDay()));
    }

    public function test_el_turno_se_rellena_con_ceros_hasta_cuatro_cifras(): void
    {
        $this->assertSame('0001', GeneradorDeTurnos::formatear(1));
        $this->assertSame('0042', GeneradorDeTurnos::formatear(42));
        $this->assertSame('9999', GeneradorDeTurnos::formatear(9999));
        // Pasado el 9999 sigue creciendo, no se corta: mejor un turno de cinco
        // cifras que dos pacientes con el mismo número.
        $this->assertSame('10000', GeneradorDeTurnos::formatear(10000));
    }

    public function test_pedir_muchos_turnos_no_repite_ninguno(): void
    {
        $sede = Sede::factory()->create();

        $turnos = [];
        for ($i = 0; $i < 50; $i++) {
            $turnos[] = $this->generador()->siguiente($sede);
        }

        $this->assertCount(50, array_unique($turnos));
        $this->assertSame('0050', end($turnos));
        $this->assertSame(50, $this->generador()->entregadosHoy($sede));
    }

    public function test_entregados_hoy_no_consume_turno(): void
    {
        $sede = Sede::factory()->create();

        $this->assertSame(0, $this->generador()->entregadosHoy($sede));

        $this->generador()->siguiente($sede);

        $this->assertSame(1, $this->generador()->entregadosHoy($sede));
        $this->assertSame(1, $this->generador()->entregadosHoy($sede));
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
        // El turno ya no lleva el prefijo: la etiqueta solo distingue la cola.
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

    /**
     * Lo dicen sus tickets, no el contador: ese pasó a ser de la sede, así que
     * una cola recién creada en una sede que ya atendió hoy aparecería como
     * usada sin haber entregado nada.
     */
    public function test_una_cola_que_ya_entrego_turnos_no_se_elimina(): void
    {
        $la30 = Sede::factory()->create();
        $cola = Cola::factory()->create(['sede_id' => $la30->id]);

        Ticket::factory()->create(['sede_id' => $la30->id, 'cola_id' => $cola->id]);

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
