<?php

namespace Tests\Feature\Tickets;

use App\Models\Auditoria;
use App\Models\Cola;
use App\Models\Sede;
use App\Models\Ticket;
use App\Models\Ventanilla;
use App\Services\Tickets\CerrarElDia;
use App\Services\Turnos\LlamadorDeTurnos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El cierre del día: lo que nadie alcanzó a atender queda «vencido».
 */
class CierreDelDiaTest extends TestCase
{
    use RefreshDatabase;

    private Sede $laTreinta;

    private Sede $bic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->laTreinta = Sede::factory()->create(['nombre' => 'LA 30', 'codigo' => 'L30']);
        $this->bic = Sede::factory()->create(['nombre' => 'EDIFICIO BIC', 'codigo' => 'BIC']);
    }

    /** Un ticket de la sede, con la fecha y el estado que se le pidan. */
    private function ticket(Sede $sede, string $estado, string $fecha, array $extra = []): Ticket
    {
        return Ticket::factory()->create([
            'sede_id' => $sede->id,
            'estado' => $estado,
            'fecha' => $fecha,
            ...$extra,
        ]);
    }

    private function ayer(): string
    {
        return now()->subDay()->toDateString();
    }

    /* ------------------------------------------------------------------ *
     *  Qué vence
     * ------------------------------------------------------------------ */

    public function test_vence_lo_que_nadie_alcanzo_a_atender_ayer(): void
    {
        $sinAlistar = $this->ticket($this->laTreinta, Ticket::ESTADO_GENERADO, $this->ayer());
        $alistando = $this->ticket($this->laTreinta, Ticket::ESTADO_EN_ALISTAMIENTO, $this->ayer());
        $listo = $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, $this->ayer());

        app(CerrarElDia::class)->handle();

        foreach ([$sinAlistar, $alistando, $listo] as $ticket) {
            $this->assertSame(Ticket::ESTADO_VENCIDO, $ticket->fresh()->estado);
            $this->assertNotNull($ticket->fresh()->cerrado_en);
        }
    }

    public function test_el_dia_en_curso_no_se_toca(): void
    {
        $hoy = $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, now()->toDateString());

        app(CerrarElDia::class)->handle();

        $this->assertSame(Ticket::ESTADO_LISTO, $hoy->fresh()->estado);
        $this->assertNull($hoy->fresh()->cerrado_en);
    }

    /**
     * El parcial es el caso que más cuesta: ese paciente **sí** fue atendido y
     * le quedaron faltantes que vuelve a reclamar otro día. Vencerlo le
     * quitaría un medicamento que ya tiene ganado.
     */
    public function test_un_parcial_no_vence_porque_el_paciente_tiene_pendientes(): void
    {
        $parcial = $this->ticket($this->laTreinta, Ticket::ESTADO_PARCIAL, now()->subDays(10)->toDateString());

        app(CerrarElDia::class)->handle();

        $this->assertSame(Ticket::ESTADO_PARCIAL, $parcial->fresh()->estado);
        $this->assertTrue($parcial->fresh()->estaEntregable());
    }

    public function test_lo_que_ya_estaba_cerrado_se_queda_como_estaba(): void
    {
        $cerradoEn = now()->subDay()->setTime(10, 30);

        $entregado = $this->ticket($this->laTreinta, Ticket::ESTADO_ENTREGADO, $this->ayer(), [
            'cerrado_en' => $cerradoEn,
        ]);
        $anulado = $this->ticket($this->laTreinta, Ticket::ESTADO_ANULADO, $this->ayer(), [
            'cerrado_en' => $cerradoEn,
            'motivo_anulacion' => 'El paciente desistió',
        ]);

        app(CerrarElDia::class)->handle();

        $this->assertSame(Ticket::ESTADO_ENTREGADO, $entregado->fresh()->estado);
        $this->assertSame(Ticket::ESTADO_ANULADO, $anulado->fresh()->estado);
        // La hora del cierre original no se pisa.
        $this->assertSame(
            $cerradoEn->format('Y-m-d H:i'),
            $entregado->fresh()->cerrado_en->format('Y-m-d H:i'),
        );
    }

    public function test_vence_tambien_lo_de_dias_anteriores_no_solo_ayer(): void
    {
        $viejo = $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, now()->subDays(12)->toDateString());

        app(CerrarElDia::class)->handle();

        $this->assertSame(Ticket::ESTADO_VENCIDO, $viejo->fresh()->estado);
    }

    /* ------------------------------------------------------------------ *
     *  Correrlo dos veces no hace daño
     * ------------------------------------------------------------------ */

    public function test_correrlo_de_nuevo_no_encuentra_nada_ni_audita_de_mas(): void
    {
        $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, $this->ayer());

        app(CerrarElDia::class)->handle();
        $segunda = app(CerrarElDia::class)->handle();

        $this->assertSame([], $segunda);
        $this->assertSame(1, Auditoria::where('accion', Auditoria::ACCION_CERRO_DIA)->count());
    }

    public function test_simular_cuenta_pero_no_escribe(): void
    {
        $listo = $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, $this->ayer());

        $resumen = app(CerrarElDia::class)->handle(simular: true);

        $this->assertSame(1, $resumen[0]['total']);
        $this->assertSame(Ticket::ESTADO_LISTO, $listo->fresh()->estado);
        $this->assertSame(0, Auditoria::where('accion', Auditoria::ACCION_CERRO_DIA)->count());
    }

    /* ------------------------------------------------------------------ *
     *  Por sede
     * ------------------------------------------------------------------ */

    public function test_se_puede_cerrar_una_sola_sede(): void
    {
        $enLa30 = $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, $this->ayer());
        $enBic = $this->ticket($this->bic, Ticket::ESTADO_LISTO, $this->ayer());

        app(CerrarElDia::class)->handle(sede: $this->laTreinta);

        $this->assertSame(Ticket::ESTADO_VENCIDO, $enLa30->fresh()->estado);
        $this->assertSame(Ticket::ESTADO_LISTO, $enBic->fresh()->estado);
    }

    public function test_el_resumen_separa_las_sedes(): void
    {
        $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, $this->ayer());
        $this->ticket($this->laTreinta, Ticket::ESTADO_GENERADO, $this->ayer());
        $this->ticket($this->bic, Ticket::ESTADO_LISTO, $this->ayer());

        $resumen = app(CerrarElDia::class)->handle();

        // De mayor a menor: LA 30 va de primera con dos.
        $this->assertCount(2, $resumen);
        $this->assertSame('LA 30', $resumen[0]['sede']);
        $this->assertSame(2, $resumen[0]['total']);
        $this->assertSame(1, $resumen[0]['por_estado'][Ticket::ESTADO_LISTO]);
        $this->assertSame(1, $resumen[0]['por_estado'][Ticket::ESTADO_GENERADO]);
        $this->assertSame('EDIFICIO BIC', $resumen[1]['sede']);
    }

    /* ------------------------------------------------------------------ *
     *  El rastro
     * ------------------------------------------------------------------ */

    public function test_deja_una_sola_linea_de_auditoria_por_sede(): void
    {
        Ticket::factory()->count(5)->create([
            'sede_id' => $this->laTreinta->id,
            'estado' => Ticket::ESTADO_LISTO,
            'fecha' => $this->ayer(),
        ]);

        app(CerrarElDia::class)->handle();

        $lineas = Auditoria::where('accion', Auditoria::ACCION_CERRO_DIA)->get();

        // Cinco tickets, una sola línea: el ticket ya cuenta su propia historia.
        $this->assertCount(1, $lineas);
        $this->assertSame($this->laTreinta->id, $lineas[0]->sede_id);
        $this->assertStringContainsString('LA 30', $lineas[0]->descripcion);
        $this->assertStringContainsString('5 tickets vencidos', $lineas[0]->descripcion);
        $this->assertSame(['5 tickets', 'vencidos'], $lineas[0]->cambios['Listo para entrega']);
    }

    public function test_el_cierre_no_deja_una_linea_por_cada_ticket(): void
    {
        Ticket::factory()->count(4)->create([
            'sede_id' => $this->laTreinta->id,
            'estado' => Ticket::ESTADO_LISTO,
            'fecha' => $this->ayer(),
        ]);

        app(CerrarElDia::class)->handle();

        // El `update` masivo no dispara el trait Auditable: ni una línea de
        // «Actualizó el ticket …». Son 4 tickets y cero ruido.
        $this->assertSame(0, Auditoria::where('accion', Auditoria::ACCION_ACTUALIZO)
            ->where('entidad_tipo', 'ticket')
            ->count());
    }

    /* ------------------------------------------------------------------ *
     *  Efecto en la sala
     * ------------------------------------------------------------------ */

    public function test_un_ticket_vencido_sale_de_la_espera(): void
    {
        $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, $this->ayer());
        $cola = Cola::factory()->create(['sede_id' => $this->laTreinta->id]);
        Ventanilla::factory()->create(['sede_id' => $this->laTreinta->id]);

        app(CerrarElDia::class)->handle();

        $llamador = app(LlamadorDeTurnos::class);

        // Ni ayer ni hoy: la fórmula ya no es entregable.
        $this->assertSame(0, $llamador->cuantosEsperan($this->laTreinta->id, [], now()->subDay()));
        $this->assertSame(0, $llamador->cuantosEsperan($this->laTreinta->id, [$cola->id]));
    }

    /* ------------------------------------------------------------------ *
     *  El comando
     * ------------------------------------------------------------------ */

    public function test_el_comando_vence_y_cuenta_lo_que_hizo(): void
    {
        $listo = $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, $this->ayer());

        $this->artisan('tickets:cerrar-dia')
            ->expectsOutputToContain('1 tickets vencidos')
            ->assertSuccessful();

        $this->assertSame(Ticket::ESTADO_VENCIDO, $listo->fresh()->estado);
    }

    public function test_el_comando_avisa_cuando_no_hay_nada_que_cerrar(): void
    {
        $this->artisan('tickets:cerrar-dia')
            ->expectsOutputToContain('No quedó ningún ticket abierto')
            ->assertSuccessful();
    }

    public function test_el_comando_simula_sin_tocar_nada(): void
    {
        $listo = $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, $this->ayer());

        $this->artisan('tickets:cerrar-dia', ['--simular' => true])
            ->expectsOutputToContain('Simulación')
            ->assertSuccessful();

        $this->assertSame(Ticket::ESTADO_LISTO, $listo->fresh()->estado);
    }

    public function test_el_comando_filtra_por_codigo_de_sede(): void
    {
        $enLa30 = $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, $this->ayer());
        $enBic = $this->ticket($this->bic, Ticket::ESTADO_LISTO, $this->ayer());

        $this->artisan('tickets:cerrar-dia', ['--sede' => 'l30'])->assertSuccessful();

        $this->assertSame(Ticket::ESTADO_VENCIDO, $enLa30->fresh()->estado);
        $this->assertSame(Ticket::ESTADO_LISTO, $enBic->fresh()->estado);
    }

    public function test_el_comando_rechaza_una_sede_que_no_existe(): void
    {
        $this->artisan('tickets:cerrar-dia', ['--sede' => 'NOEXISTE'])
            ->expectsOutputToContain('No hay ninguna sede')
            ->assertFailed();
    }

    /** El día en curso no se cierra: todavía hay pacientes en la sala. */
    public function test_el_comando_no_deja_cerrar_hoy(): void
    {
        $hoy = $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, now()->toDateString());

        $this->artisan('tickets:cerrar-dia', ['--fecha' => now()->toDateString()])
            ->expectsOutputToContain('El día en curso no se cierra')
            ->assertFailed();

        $this->assertSame(Ticket::ESTADO_LISTO, $hoy->fresh()->estado);
    }

    public function test_el_comando_rechaza_una_fecha_mal_escrita(): void
    {
        $this->artisan('tickets:cerrar-dia', ['--fecha' => '04/10/2026'])
            ->expectsOutputToContain('YYYY-MM-DD')
            ->assertFailed();
    }

    public function test_con_fecha_cierra_hasta_ese_dia_inclusive(): void
    {
        $viejo = $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, now()->subDays(5)->toDateString());
        $delDia = $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, now()->subDays(3)->toDateString());
        $posterior = $this->ticket($this->laTreinta, Ticket::ESTADO_LISTO, now()->subDay()->toDateString());

        $this->artisan('tickets:cerrar-dia', ['--fecha' => now()->subDays(3)->toDateString()])
            ->assertSuccessful();

        $this->assertSame(Ticket::ESTADO_VENCIDO, $viejo->fresh()->estado);
        $this->assertSame(Ticket::ESTADO_VENCIDO, $delDia->fresh()->estado);
        $this->assertSame(Ticket::ESTADO_LISTO, $posterior->fresh()->estado);
    }
}
