<?php

namespace Tests\Feature\Sedes;

use App\Filament\Resources\TicketResource;
use App\Livewire\SelectorDeSede;
use App\Models\Auditoria;
use App\Models\Cola;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\GenerarTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Moverse entre sedes.
 *
 * Quien cubre más de una sede necesita poder cambiarse sin pedírselo a un
 * administrador. Pero la sede es lo que decide a qué pacientes y a qué
 * fórmulas llega cada quien, así que **solo se mueve entre las que le
 * asignaron**.
 */
class CambioDeSedeTest extends TestCase
{
    use RefreshDatabase;

    private Sede $la30;

    private Sede $premium;

    private Sede $aventura;

    protected function setUp(): void
    {
        parent::setUp();

        $this->la30 = Sede::factory()->create(['nombre' => 'LA 30', 'codigo' => 'L30']);
        $this->premium = Sede::factory()->create(['nombre' => 'PREMIUM PLAZA', 'codigo' => 'PRP']);
        $this->aventura = Sede::factory()->create(['nombre' => 'AVENTURA', 'codigo' => 'AVT']);

        Rol::create(['nombre' => 'ORIENTADOR', 'permisos' => [
            'orientacion' => ['usar'],
            'tickets' => ['ver'],
        ]]);
    }

    /**
     * @param  list<Sede>  $asignadas
     */
    private function usuarioEn(Sede $actual, array $asignadas = []): User
    {
        $usuario = User::factory()->create([
            'sede_id' => $actual->id,
            'roles' => ['ORIENTADOR'],
        ]);

        $usuario->sedes()->sync(collect($asignadas ?: [$actual])->pluck('id')->all());

        return $usuario->fresh();
    }

    /* ------------------------------------------------------------------ *
     *  Entre cuáles puede moverse
     * ------------------------------------------------------------------ */

    public function test_con_una_sola_sede_no_hay_nada_que_escoger(): void
    {
        $usuario = $this->usuarioEn($this->la30);

        $this->assertFalse($usuario->puedeCambiarDeSede());
        $this->assertSame(['LA 30'], $usuario->sedesDondePuedeTrabajar()->pluck('nombre')->all());
    }

    public function test_con_varias_asignadas_puede_moverse_entre_ellas(): void
    {
        $usuario = $this->usuarioEn($this->la30, [$this->la30, $this->premium]);

        $this->assertTrue($usuario->puedeCambiarDeSede());
        $this->assertEqualsCanonicalizing(
            ['LA 30', 'PREMIUM PLAZA'],
            $usuario->sedesDondePuedeTrabajar()->pluck('nombre')->all(),
        );
    }

    /**
     * El administrador ya ve todas las sedes en cada listado: limitarle el
     * selector sería incoherente.
     */
    public function test_el_administrador_se_mueve_por_todas_sin_que_nadie_se_las_asigne(): void
    {
        $admin = User::factory()->administrador()->create(['sede_id' => $this->la30->id]);

        $this->assertTrue($admin->puedeCambiarDeSede());
        $this->assertCount(3, $admin->sedesDondePuedeTrabajar());
    }

    /**
     * Si un administrador le quita la sede mientras está trabajando, el
     * selector tiene que seguir diciendo dónde está.
     */
    public function test_la_sede_actual_siempre_aparece_aunque_se_la_hayan_quitado(): void
    {
        $usuario = $this->usuarioEn($this->la30, [$this->premium]);

        $this->assertEqualsCanonicalizing(
            ['LA 30', 'PREMIUM PLAZA'],
            $usuario->sedesDondePuedeTrabajar()->pluck('nombre')->all(),
        );
    }

    /* ------------------------------------------------------------------ *
     *  Mudarse
     * ------------------------------------------------------------------ */

    public function test_cambiar_de_sede_mueve_al_usuario(): void
    {
        $usuario = $this->usuarioEn($this->la30, [$this->la30, $this->premium]);
        $this->actingAs($usuario);

        Livewire::test(SelectorDeSede::class)->call('cambiar', $this->premium->id);

        $this->assertSame($this->premium->id, $usuario->fresh()->sede_id);
    }

    /**
     * El selector solo ofrece las permitidas, pero el id viaja en la petición:
     * la comprobación de verdad está en el servidor.
     */
    public function test_no_se_puede_entrar_a_una_sede_que_no_le_asignaron(): void
    {
        $usuario = $this->usuarioEn($this->la30, [$this->la30, $this->premium]);
        $this->actingAs($usuario);

        Livewire::test(SelectorDeSede::class)->call('cambiar', $this->aventura->id);

        $this->assertSame($this->la30->id, $usuario->fresh()->sede_id);
    }

    public function test_cambiar_de_sede_queda_en_la_auditoria(): void
    {
        $usuario = $this->usuarioEn($this->la30, [$this->la30, $this->premium]);
        $this->actingAs($usuario);

        Livewire::test(SelectorDeSede::class)->call('cambiar', $this->premium->id);

        $registro = Auditoria::where('accion', Auditoria::ACCION_CAMBIO_DE_SEDE)->sole();

        $this->assertSame($usuario->id, $registro->usuario_id);
        $this->assertStringContainsString('LA 30', $registro->descripcion);
        $this->assertStringContainsString('PREMIUM PLAZA', $registro->descripcion);
        $this->assertSame($this->premium->id, $registro->sede_id);
    }

    public function test_quedarse_en_la_misma_sede_no_ensucia_la_auditoria(): void
    {
        $usuario = $this->usuarioEn($this->la30, [$this->la30, $this->premium]);
        $this->actingAs($usuario);

        Livewire::test(SelectorDeSede::class)->call('cambiar', $this->la30->id);

        $this->assertSame(0, Auditoria::where('accion', Auditoria::ACCION_CAMBIO_DE_SEDE)->count());
    }

    /**
     * La ventanilla guardada en la sesión es de la sede anterior: llamar
     * turnos con ella sería llamar desde el mostrador equivocado.
     */
    public function test_al_mudarse_se_suelta_la_ventanilla_de_la_sede_anterior(): void
    {
        $usuario = $this->usuarioEn($this->la30, [$this->la30, $this->premium]);
        $this->actingAs($usuario);

        session(['turnos.ventanilla' => 99]);

        Livewire::test(SelectorDeSede::class)->call('cambiar', $this->premium->id);

        $this->assertNull(session('turnos.ventanilla'));
    }

    /* ------------------------------------------------------------------ *
     *  Lo que cambia al mudarse
     * ------------------------------------------------------------------ */

    /**
     * Lo importante: cambiar de sede cambia de verdad lo que la persona ve,
     * porque todo el sistema lee `users.sede_id`.
     */
    public function test_tras_mudarse_ve_los_tickets_de_la_sede_nueva_y_no_los_de_la_vieja(): void
    {
        $usuario = $this->usuarioEn($this->la30, [$this->la30, $this->premium]);
        $this->actingAs($usuario);

        $deLa30 = Ticket::factory()->create(['sede_id' => $this->la30->id, 'creado_por' => $usuario->id]);
        $dePremium = Ticket::factory()->create(['sede_id' => $this->premium->id, 'creado_por' => $usuario->id]);

        $visibles = fn (): array => TicketResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$deLa30->id], $visibles());

        Livewire::test(SelectorDeSede::class)->call('cambiar', $this->premium->id);
        $this->actingAs($usuario->fresh());

        $this->assertSame([$dePremium->id], $visibles());
    }

    /**
     * El turno nuevo sale de la sede donde está parado, no de la original.
     */
    public function test_el_ticket_que_genere_nace_en_la_sede_a_la_que_se_mudo(): void
    {
        $usuario = $this->usuarioEn($this->la30, [$this->la30, $this->premium]);

        foreach ([$this->la30, $this->premium] as $sede) {
            Cola::factory()->create([
                'sede_id' => $sede->id,
                'nombre' => 'Dispensación general',
                'prefijo' => 'A',
                'orden' => 1,
                'activa' => true,
                'atiende_alto_costo' => false,
            ]);
        }

        $this->actingAs($usuario);
        Livewire::test(SelectorDeSede::class)->call('cambiar', $this->premium->id);

        $paciente = Paciente::factory()->create();

        $ticket = app(GenerarTicket::class)->handle(
            $paciente,
            $usuario->fresh()->sede,
            $usuario->fresh(),
        );

        $this->assertSame($this->premium->id, $ticket->sede_id);
        $this->assertStringStartsWith('TK-PRP-', $ticket->numero);
    }

    /* ------------------------------------------------------------------ *
     *  El selector en pantalla
     * ------------------------------------------------------------------ */

    public function test_el_selector_no_se_pinta_con_una_sola_sede(): void
    {
        $this->actingAs($this->usuarioEn($this->la30));

        Livewire::test(SelectorDeSede::class)
            // Ni el botón de la barra ni ninguna sede: no hay a dónde moverse.
            ->assertDontSee('Cambiar de sede')
            ->assertDontSee('LA 30 (L30)');
    }

    public function test_el_selector_lista_las_sedes_disponibles(): void
    {
        $this->actingAs($this->usuarioEn($this->la30, [$this->la30, $this->premium]));

        Livewire::test(SelectorDeSede::class)
            ->assertSee('Cambiar de sede')
            ->assertSee('LA 30 (L30)')
            ->assertSee('PREMIUM PLAZA (PRP)')
            // La que no le asignaron no aparece.
            ->assertDontSee('AVENTURA (AVT)');
    }
}
