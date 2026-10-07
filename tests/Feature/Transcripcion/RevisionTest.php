<?php

namespace Tests\Feature\Transcripcion;

use App\Filament\Resources\TranscripcionResource\Pages\RevisarTranscripcion as Pantalla;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Transcripcion;
use App\Models\User;
use App\Services\Transcripcion\RevisarTranscripcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** Fase 2: tomar, corregir, confirmar o rechazar una transcripción. */
class RevisionTest extends TestCase
{
    use RefreshDatabase;

    private Transcripcion $t;

    private User $ana;

    private User $beto;

    private RevisarTranscripcion $reglas;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::fake();

        Rol::create(['nombre' => 'TRANSCRIPCION', 'permisos' => ['transcripcion' => ['ver', 'transcribir'], 'orientacion' => ['ver_orden']]]);
        $this->ana = User::factory()->create(['roles' => ['TRANSCRIPCION'], 'nombre' => 'Ana']);
        $this->beto = User::factory()->create(['roles' => ['TRANSCRIPCION'], 'nombre' => 'Beto', 'sede_id' => $this->ana->sede_id]);
        $this->reglas = app(RevisarTranscripcion::class);

        // Cargar la orden dispara la lectura (sin clave → fallida); se deja como si Gemini ya la hubiera leído.
        $paciente = Paciente::factory()->create(['numero_documento' => '1000873458']);
        $ruta = UploadedFile::fake()->image('orden.jpg')->storeAs('soportes/ordenes-medicas', 'orden.jpg', ['disk' => 'local']);
        $paciente->soportes()->create(['orden_medica' => $ruta, 'alto_costo_oncologico' => false, 'cargado_por' => $this->ana->id]);

        $this->t = Transcripcion::sole();
        $this->t->update([
            'estado' => Transcripcion::ESTADO_LEIDA,
            'verificacion_cedula' => Transcripcion::CEDULA_COINCIDE,
            'formula' => ['formulas' => [
                ['numero' => 1, 'ips' => 'CS CISAMF', 'fecha_expedicion' => today()->subDays(3)->toDateString(), 'medico' => ['nombre' => 'CLAUDIA BETANCOURT']],
            ]],
        ]);
        $this->t->items()->create([
            'formula' => 1, 'texto_prescrito' => 'Losartan 50 mg tableta', 'codigo_inventario' => 'MX377-6',
            'cantidad_total' => 180, 'duracion_dias' => 90, 'meses' => 3, 'entrega_mes' => 1, 'cantidad_mes' => 60,
            'revisado' => true,
        ]);
    }

    private function item(array $cambios = []): array
    {
        return array_merge($this->t->items()->sole()->only([
            'id', 'formula', 'texto_prescrito', 'codigo_inventario', 'cantidad_total', 'duracion_dias', 'meses', 'entrega_mes', 'cantidad_mes',
        ]), $cambios);
    }

    public function test_solo_una_persona_la_revisa_a_la_vez(): void
    {
        $this->reglas->tomar($this->t, $this->ana);

        $this->expectExceptionMessage('La está revisando Ana');
        $this->reglas->tomar($this->t->fresh(), $this->beto);
    }

    public function test_sin_tomarla_no_se_puede_tocar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->reglas->guardar($this->t, $this->ana, $this->t->formulas(), [$this->item()]);
    }

    public function test_corregir_marca_quien_edito_y_recalcula_la_sugerencia(): void
    {
        $this->reglas->tomar($this->t, $this->ana);

        // Cambia el producto por uno de otra concentración y agrega una línea de una segunda fórmula.
        $this->reglas->guardar($this->t->fresh(), $this->ana, [...$this->t->formulas(), ['numero' => 2, 'ips' => 'METROSALUD']], [
            $this->item(['codigo_inventario' => 'MOCK-6']),
            ['formula' => 2, 'texto_prescrito' => 'Metformina 850 mg tableta', 'codigo_inventario' => 'MOCK-11', 'meses' => 1, 'entrega_mes' => 1, 'cantidad_mes' => 30],
        ]);

        $items = $this->t->items()->orderBy('id')->get();
        $this->assertSame($this->ana->id, $items[0]->editado_por);
        $this->assertStringContainsString('Difiere concentración', implode(' ', $items[0]->alertas));
        $this->assertSame(2, $items[1]->formula);
        $this->assertCount(2, $this->t->fresh()->formulas());
    }

    public function test_confirmar_exige_lo_que_falta(): void
    {
        $this->t->update(['verificacion_cedula' => Transcripcion::CEDULA_NO_ENCONTRADA]);
        $this->reglas->tomar($this->t, $this->ana);
        $this->reglas->guardar($this->t->fresh(), $this->ana,
            [['numero' => 1, 'ips' => 'CS CISAMF', 'fecha_expedicion' => today()->addDay()->toDateString(), 'vigencia' => today()->subDay()->toDateString(), 'medico' => ['nombre' => 'X']]],
            [$this->item(['codigo_inventario' => null, 'cantidad_mes' => 200, 'entrega_mes' => 1])],
        );

        $faltas = $this->reglas->faltasParaConfirmar($this->t->fresh(), cedulaRevisada: false);
        $texto = implode("\n", $faltas);

        $this->assertStringContainsString('cédula no se encontró', $texto);
        $this->assertStringContainsString('es futura', $texto);
        $this->assertStringContainsString('venció', $texto);
        $this->assertStringContainsString('elige el producto', $texto);
        $this->assertStringContainsString('supera el total', $texto);
        $this->assertSame(Transcripcion::ESTADO_EN_REVISION, $this->t->fresh()->estado);
    }

    public function test_confirmar_con_cedula_revisada_a_mano(): void
    {
        $this->t->update(['verificacion_cedula' => Transcripcion::CEDULA_NO_ENCONTRADA]);
        $this->reglas->tomar($this->t, $this->ana);

        $this->reglas->confirmar($this->t->fresh(), $this->ana, cedulaRevisada: true);

        $t = $this->t->fresh();
        $this->assertSame(Transcripcion::ESTADO_CONFIRMADA, $t->estado);
        $this->assertSame(Transcripcion::CEDULA_REVISADA, $t->verificacion_cedula);
        $this->assertSame($this->ana->id, $t->confirmada_por);
    }

    public function test_una_formula_vencida_se_marca_no_dispensable_y_las_demas_siguen(): void
    {
        $this->reglas->tomar($this->t, $this->ana);
        $formulas = [
            ['numero' => 1, 'ips' => 'CS CISAMF', 'fecha_expedicion' => today()->subDays(3)->toDateString(), 'medico' => ['nombre' => 'CLAUDIA BETANCOURT']],
            ['numero' => 2, 'ips' => 'METROSALUD', 'fecha_expedicion' => '2026-08-12', 'vigencia' => '2026-08-17', 'medico' => ['nombre' => 'NORMA PALACIO']],
        ];
        $items = [$this->item(), ['formula' => 2, 'texto_prescrito' => 'Levotiroxina 25 mcg']]; // sin producto ni cantidades

        $this->reglas->guardar($this->t->fresh(), $this->ana, $formulas, $items);
        $this->assertStringContainsString('márcala «No se dispensa»', implode(' ', $this->reglas->faltasParaConfirmar($this->t->fresh(), false)));

        // Marcada sin motivo: se pide el motivo.
        $formulas[1]['rechazada'] = true;
        $this->reglas->guardar($this->t->fresh(), $this->ana, $formulas, $items);
        $this->assertSame(['Fórmula #2: elige el motivo por el que no se dispensa.'], $this->reglas->faltasParaConfirmar($this->t->fresh(), false));

        // Con motivo: su línea ya no exige producto ni cantidades y se puede confirmar.
        $formulas[1]['motivo_rechazo'] = 'vencida';
        $this->reglas->guardar($this->t->fresh(), $this->ana, $formulas, $items);
        $this->assertSame([], $this->reglas->faltasParaConfirmar($this->t->fresh(), false));
        $this->assertSame([2], $this->t->fresh()->formulasRechazadas());

        // Si todas quedan marcadas, toca rechazar la transcripción completa.
        $formulas[0] += ['rechazada' => true, 'motivo_rechazo' => 'ilegible'];
        $this->reglas->guardar($this->t->fresh(), $this->ana, $formulas, $items);
        $this->assertStringContainsString('rechaza la transcripción completa', implode(' ', $this->reglas->faltasParaConfirmar($this->t->fresh(), false)));
    }

    public function test_rechazar_guarda_el_motivo(): void
    {
        $this->reglas->tomar($this->t, $this->ana);
        $this->reglas->rechazar($this->t->fresh(), $this->ana, 'ilegible');

        $this->assertSame('ilegible', $this->t->fresh()->motivo_rechazo);
        $this->assertSame(Transcripcion::ESTADO_RECHAZADA, $this->t->fresh()->estado);
    }

    public function test_la_pantalla_toma_corrige_y_confirma(): void
    {
        $this->actingAs($this->ana);

        $pantalla = Livewire::test(Pantalla::class, ['record' => $this->t->getKey()])
            ->assertSee('Losartan 50 mg tableta')
            ->assertFormFieldIsDisabled('formulas')
            ->callAction('tomar')
            ->assertFormFieldIsEnabled('formulas');

        // El repeater usa claves UUID, no 0, 1, 2.
        $clave = array_key_first($pantalla->get('data.items'));

        $pantalla->set("data.items.{$clave}.cantidad_mes", 30)
            ->callAction('guardar')
            ->callAction('confirmar')
            ->assertHasNoActionErrors();

        $t = $this->t->fresh();
        $this->assertSame(Transcripcion::ESTADO_CONFIRMADA, $t->estado);
        $this->assertEquals(30, $t->items()->sole()->cantidad_mes);
    }

    public function test_no_se_confirma_con_lineas_sin_revisar(): void
    {
        $this->t->items()->update(['revisado' => false]);
        $this->reglas->tomar($this->t, $this->ana);

        $this->assertStringContainsString('márcala «Revisado»', implode(' ', $this->reglas->faltasParaConfirmar($this->t->fresh(), false)));
    }

    public function test_la_previsualizacion_muestra_el_acta_y_marca_revisado(): void
    {
        $this->t->items()->update(['revisado' => false]);
        $this->actingAs($this->ana);

        $pantalla = Livewire::test(Pantalla::class, ['record' => $this->t->getKey()])
            ->assertSee('Previsualización del acta')
            ->assertSee('Fórmula #1 · CS CISAMF')
            ->assertSee('Revisadas 0 de 1')
            ->callAction('tomar');

        $clave = array_key_first($pantalla->get('data.items'));
        $pantalla->call('alternarRevisado', $clave)
            ->assertSet("data.items.{$clave}.revisado", true)
            ->assertSee('Revisadas 1 de 1')
            // Una fórmula marcada «no se dispensa» sale así en el acta, sin guardar.
            ->set('data.formulas.'.array_key_first($pantalla->get('data.formulas')).'.rechazada', true)
            ->assertSee('NO SE DISPENSA');
    }

    public function test_otra_persona_la_ve_en_solo_lectura(): void
    {
        $this->reglas->tomar($this->t, $this->ana);
        $this->actingAs($this->beto);

        Livewire::test(Pantalla::class, ['record' => $this->t->getKey()])
            ->assertSee('La está revisando Ana')
            ->assertFormFieldIsDisabled('formulas')
            ->assertActionHidden('confirmar');
    }
}
