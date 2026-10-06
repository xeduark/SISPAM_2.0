<?php

namespace Tests\Feature\Orientacion;

use App\Filament\Resources\PacienteResource\Pages\ViewPaciente;
use App\Models\Auditoria;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Soporte;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Soportes\GeneradorDeMiniaturas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ver las fórmulas después de cargarlas.
 *
 * Lo que más importa aquí: **ninguna fórmula se abre sin permiso, y ninguna
 * se abre sin dejar rastro**. Las miniaturas son la excepción deliberada, y
 * tienen su propia prueba.
 */
class GaleriaDeFormulasTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private User $orientador;

    private Paciente $paciente;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->sede = Sede::factory()->create(['nombre' => 'PREMIUM PLAZA', 'codigo' => 'PRP']);

        Rol::create(['nombre' => 'ORIENTADOR', 'permisos' => [
            'pacientes' => ['ver'],
            'orientacion' => ['usar', 'ver_orden'],
        ]]);

        Rol::create(['nombre' => 'CAJERO', 'permisos' => [
            'pacientes' => ['ver'],
        ]]);

        $this->orientador = User::factory()->create([
            'roles' => ['ORIENTADOR'],
            'sede_id' => $this->sede->id,
        ]);

        $this->paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
        ]);

        $this->actingAs($this->orientador);
    }

    /* ------------------------------------------------------------------ *
     *  Andamiaje
     * ------------------------------------------------------------------ */

    private function ticket(array $extra = []): Ticket
    {
        return Ticket::factory()->create(array_merge([
            'paciente_id' => $this->paciente->id,
            'sede_id' => $this->sede->id,
            'fecha' => now()->toDateString(),
            'estado' => Ticket::ESTADO_LISTO,
            'creado_por' => $this->orientador->id,
        ], $extra));
    }

    /**
     * Una hoja de verdad en el disco, para poder reducirla.
     */
    private function hoja(?Ticket $ticket, int $pagina = 1, string $extension = 'jpg'): Soporte
    {
        $ruta = "soportes/ordenes-medicas/hoja-{$pagina}-".uniqid().".{$extension}";

        $contenido = $extension === 'pdf'
            ? '%PDF-1.4 falso'
            : UploadedFile::fake()->image('x.jpg', 1600, 1200)->get();

        Storage::disk('local')->put($ruta, $contenido);

        return $this->paciente->soportes()->create([
            'ticket_id' => $ticket?->id,
            'orden_medica' => $ruta,
            'pagina' => $pagina,
            'mime' => $extension === 'pdf' ? 'application/pdf' : 'image/jpeg',
            'cargado_por' => $this->orientador->id,
        ]);
    }

    /* ------------------------------------------------------------------ *
     *  Agrupar por visita
     * ------------------------------------------------------------------ */

    public function test_las_formulas_se_agrupan_por_visita(): void
    {
        $primera = $this->ticket();
        $segunda = $this->ticket();

        $this->hoja($primera, 1);
        $this->hoja($primera, 2);
        $this->hoja($segunda, 1);

        $visitas = $this->paciente->formulasPorVisita();

        $this->assertCount(2, $visitas);
        $this->assertCount(1, $visitas[$segunda->id]);
        $this->assertCount(2, $visitas[$primera->id]);
    }

    public function test_la_visita_mas_reciente_va_primero(): void
    {
        $vieja = $this->ticket();
        $nueva = $this->ticket();

        $this->hoja($vieja);
        $this->hoja($nueva);

        $this->assertSame(
            $nueva->id,
            $this->paciente->formulasPorVisita()->keys()->first(),
        );
    }

    public function test_las_formulas_sin_ticket_van_en_su_propio_grupo_al_final(): void
    {
        $ticket = $this->ticket();

        $this->hoja($ticket);
        $this->hoja(null);

        $visitas = $this->paciente->formulasPorVisita();

        $this->assertSame(
            [$ticket->id, 'sin-visita'],
            $visitas->keys()->all(),
        );
    }

    public function test_dentro_de_una_visita_las_hojas_van_en_orden(): void
    {
        $ticket = $this->ticket();

        $this->hoja($ticket, 3);
        $this->hoja($ticket, 1);
        $this->hoja($ticket, 2);

        $this->assertSame(
            [1, 2, 3],
            $this->paciente->formulasPorVisita()[$ticket->id]->pluck('pagina')->all(),
        );
    }

    /* ------------------------------------------------------------------ *
     *  Miniaturas
     * ------------------------------------------------------------------ */

    public function test_la_miniatura_se_genera_la_primera_vez_que_se_pide(): void
    {
        $hoja = $this->hoja($this->ticket());

        $this->assertNull($hoja->miniatura);

        $this->get(route('soportes.miniatura', $hoja))->assertOk();

        $hoja->refresh();
        $this->assertNotNull($hoja->miniatura);
        Storage::disk('local')->assertExists($hoja->miniatura);
    }

    public function test_la_miniatura_no_se_vuelve_a_generar_en_la_siguiente_visita(): void
    {
        $hoja = $this->hoja($this->ticket());

        $this->get(route('soportes.miniatura', $hoja))->assertOk();
        $primera = $hoja->fresh()->miniatura;

        $this->get(route('soportes.miniatura', $hoja))->assertOk();

        $this->assertSame($primera, $hoja->fresh()->miniatura);
    }

    public function test_la_miniatura_queda_en_el_disco_privado_y_pesa_menos(): void
    {
        $hoja = $this->hoja($this->ticket());

        $this->get(route('soportes.miniatura', $hoja))->assertOk();

        $disco = Storage::disk('local');
        $ruta = $hoja->fresh()->miniatura;

        $this->assertStringStartsWith(GeneradorDeMiniaturas::DIRECTORIO, $ruta);
        $this->assertLessThan($disco->size($hoja->orden_medica), $disco->size($ruta));

        // El lado largo no pasa del tope.
        [$ancho, $alto] = getimagesizefromstring($disco->get($ruta));
        $this->assertLessThanOrEqual(GeneradorDeMiniaturas::LADO, max($ancho, $alto));
    }

    public function test_un_pdf_no_tiene_miniatura(): void
    {
        $hoja = $this->hoja($this->ticket(), 1, 'pdf');

        $this->get(route('soportes.miniatura', $hoja))->assertNotFound();

        $this->assertNull($hoja->fresh()->miniatura);
    }

    public function test_un_archivo_que_ya_no_esta_no_revienta_la_galeria(): void
    {
        $hoja = $this->hoja($this->ticket());

        Storage::disk('local')->delete($hoja->orden_medica);

        $this->get(route('soportes.miniatura', $hoja))->assertNotFound();
    }

    /* ------------------------------------------------------------------ *
     *  Nadie ve una fórmula sin permiso
     * ------------------------------------------------------------------ */

    public function test_sin_permiso_no_se_abre_una_formula(): void
    {
        $hoja = $this->hoja($this->ticket());

        $this->actingAs(User::factory()->create([
            'roles' => ['CAJERO'],
            'sede_id' => $this->sede->id,
        ]));

        $this->get(route('soportes.orden-medica', $hoja))->assertForbidden();
    }

    /**
     * La miniatura es una versión reducida del mismo dato de salud: exige lo
     * mismo que el original.
     */
    public function test_sin_permiso_tampoco_se_ve_la_miniatura(): void
    {
        $hoja = $this->hoja($this->ticket());

        $this->actingAs(User::factory()->create([
            'roles' => ['CAJERO'],
            'sede_id' => $this->sede->id,
        ]));

        $this->get(route('soportes.miniatura', $hoja))->assertForbidden();

        // Y no se generó de paso.
        $this->assertNull($hoja->fresh()->miniatura);
    }

    public function test_sin_sesion_no_se_llega_a_ninguna_de_las_dos(): void
    {
        $hoja = $this->hoja($this->ticket());

        auth()->logout();

        $this->get(route('soportes.orden-medica', $hoja))->assertRedirect();
        $this->get(route('soportes.miniatura', $hoja))->assertRedirect();
    }

    public function test_sin_el_permiso_la_galeria_no_se_pinta(): void
    {
        $this->hoja($this->ticket());

        $this->actingAs(User::factory()->create([
            'roles' => ['CAJERO'],
            'sede_id' => $this->sede->id,
        ]));

        Livewire::test(ViewPaciente::class, ['record' => $this->paciente->getRouteKey()])
            ->assertDontSee('Fórmulas médicas');
    }

    /* ------------------------------------------------------------------ *
     *  Nadie abre una fórmula sin dejar rastro
     * ------------------------------------------------------------------ */

    public function test_abrir_una_formula_queda_en_la_auditoria(): void
    {
        $hoja = $this->hoja($this->ticket());

        $this->get(route('soportes.orden-medica', $hoja))->assertOk();

        $registro = Auditoria::where('accion', Auditoria::ACCION_DESCARGO_ORDEN)->sole();

        $this->assertStringContainsString('1017234567', $registro->descripcion);
        $this->assertSame($this->orientador->id, $registro->usuario_id);
    }

    public function test_entrar_a_la_galeria_deja_una_linea_y_no_una_por_hoja(): void
    {
        $ticket = $this->ticket();

        foreach (range(1, 5) as $pagina) {
            $this->hoja($ticket, $pagina);
        }

        Livewire::test(ViewPaciente::class, ['record' => $this->paciente->getRouteKey()]);

        $this->assertSame(1, Auditoria::where('accion', Auditoria::ACCION_VIO_GALERIA)->count());
    }

    /**
     * Las miniaturas son la excepción a propósito: una línea por imagen
     * pintada enterraría el rastro de quién sí leyó la fórmula.
     */
    public function test_pedir_miniaturas_no_ensucia_el_rastro_de_quien_leyo_la_formula(): void
    {
        $ticket = $this->ticket();

        $hojas = collect(range(1, 5))->map(fn (int $p): Soporte => $this->hoja($ticket, $p));

        foreach ($hojas as $hoja) {
            $this->get(route('soportes.miniatura', $hoja))->assertOk();
        }

        $this->assertSame(0, Auditoria::where('accion', Auditoria::ACCION_DESCARGO_ORDEN)->count());
    }

    public function test_un_paciente_sin_formulas_no_deja_rastro_de_galeria(): void
    {
        Livewire::test(ViewPaciente::class, ['record' => $this->paciente->getRouteKey()]);

        $this->assertSame(0, Auditoria::where('accion', Auditoria::ACCION_VIO_GALERIA)->count());
    }

    public function test_sin_permiso_entrar_a_la_ficha_no_deja_rastro_de_galeria(): void
    {
        $this->hoja($this->ticket());

        $this->actingAs(User::factory()->create([
            'roles' => ['CAJERO'],
            'sede_id' => $this->sede->id,
        ]));

        Livewire::test(ViewPaciente::class, ['record' => $this->paciente->getRouteKey()]);

        $this->assertSame(0, Auditoria::where('accion', Auditoria::ACCION_VIO_GALERIA)->count());
    }

    /**
     * De la galería solo queda el documento del paciente: ni su nombre ni
     * nada de lo que dice la fórmula.
     */
    public function test_el_rastro_de_la_galeria_no_lleva_nada_clinico(): void
    {
        $this->paciente->update(['primer_nombre' => 'MARIA', 'primer_apellido' => 'GOMEZ']);
        $hoja = $this->hoja($this->ticket());

        Livewire::test(ViewPaciente::class, ['record' => $this->paciente->getRouteKey()]);

        $registro = Auditoria::where('accion', Auditoria::ACCION_VIO_GALERIA)->sole();

        $this->assertStringContainsString('1017234567', $registro->descripcion);
        $this->assertStringNotContainsString('MARIA', $registro->descripcion);
        $this->assertStringNotContainsString('GOMEZ', $registro->descripcion);
        $this->assertStringNotContainsString($hoja->orden_medica, (string) $registro->descripcion);
    }

    /* ------------------------------------------------------------------ *
     *  La galería en pantalla
     * ------------------------------------------------------------------ */

    public function test_la_galeria_muestra_las_visitas_con_su_turno(): void
    {
        $ticket = $this->ticket();
        $this->hoja($ticket);

        Livewire::test(ViewPaciente::class, ['record' => $this->paciente->getRouteKey()])
            ->assertSee('Fórmulas médicas')
            ->assertSee($ticket->turno)
            ->assertSee($ticket->numero);
    }

    public function test_la_galeria_apunta_a_las_rutas_protegidas_y_nunca_al_disco(): void
    {
        $hoja = $this->hoja($this->ticket());

        $pagina = Livewire::test(ViewPaciente::class, ['record' => $this->paciente->getRouteKey()]);

        $pagina->assertSee(route('soportes.miniatura', $hoja), escape: false);

        // La ruta interna del archivo nunca se filtra al HTML.
        $pagina->assertDontSee($hoja->orden_medica);
    }
}
