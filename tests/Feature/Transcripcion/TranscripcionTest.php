<?php

namespace Tests\Feature\Transcripcion;

use App\Filament\Resources\TranscripcionResource\Pages\ListTranscripciones;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Soporte;
use App\Models\Transcripcion;
use App\Models\User;
use App\Services\Transcripcion\InterpretarFormula;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cada orden médica cargada se lee con Google Vision y queda como propuesta
 * para la transcriptora. Ninguna prueba toca Vision real.
 */
class TranscripcionTest extends TestCase
{
    use RefreshDatabase;

    private const TEXTO_FORMULA = <<<'TXT'
        CS CISAMF
        Paciente: JUAN RAMIREZ  CC 1.000.873.458
        Médico: CLAUDIA BETANCOURT  Reg. 43.152.152
        Losartan 50 mg tableta  # 120
        Rosuvastatina 40 mg + Ezetimibe 10 mg tableta recubierta # 60
        Tomar 1 cada 12 horas
        TXT;

    private Paciente $paciente;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1000873458',
            'primer_nombre' => 'JUAN',
            'primer_apellido' => 'RAMIREZ',
        ]);
    }

    private function cargarOrden(): Soporte
    {
        $ruta = UploadedFile::fake()->image('orden.jpg')
            ->storeAs('soportes/ordenes-medicas', 'orden.jpg', ['disk' => 'local']);

        return $this->paciente->soportes()->create([
            'orden_medica' => $ruta,
            'alto_costo_oncologico' => false,
            'cargado_por' => User::factory()->create()->id,
        ]);
    }

    public function test_al_cargar_la_orden_se_lee_y_queda_por_revisar(): void
    {
        config(['services.google_vision.key' => 'clave-de-prueba']);
        Http::fake(['vision.googleapis.com/*' => Http::response([
            'responses' => [['fullTextAnnotation' => ['text' => self::TEXTO_FORMULA]]],
        ])]);

        $this->cargarOrden();
        $transcripcion = Transcripcion::sole();

        $this->assertSame(Transcripcion::ESTADO_LEIDA, $transcripcion->estado);
        $this->assertSame(Transcripcion::CEDULA_COINCIDE, $transcripcion->verificacion_cedula);
        $this->assertSame(self::TEXTO_FORMULA, $transcripcion->texto_ocr);

        // «Tomar 1 cada 12 horas» no tiene concentración: no es un medicamento.
        $items = $transcripcion->items()->orderBy('id')->get();
        $this->assertCount(2, $items);

        $this->assertSame('MX377-6', $items[0]->codigo_inventario);
        $this->assertEquals(120, $items[0]->cantidad_total);
        $this->assertSame([], $items[0]->alertas);

        // El orden de los componentes no importa: empata con su combinación en bodega.
        $this->assertSame('MX1093-1', $items[1]->codigo_inventario);
        $this->assertSame([], $items[1]->alertas);

        // La clave va en el encabezado, nunca en la URL.
        Http::assertSent(fn ($peticion) => $peticion->hasHeader('X-Goog-Api-Key', 'clave-de-prueba')
            && ! str_contains($peticion->url(), 'clave-de-prueba'));
    }

    public function test_con_gemini_la_formula_llega_ordenada(): void
    {
        config(['services.transcripcion.motor' => 'gemini', 'services.gemini.key' => 'clave-gemini']);
        $lectura = [
            'paciente' => ['tipo_documento' => 'CC', 'numero_documento' => '1000873458', 'nombre_completo' => 'JUAN RAMIREZ'],
            'formulas' => [
                ['numero' => 1, 'ips' => 'CS CISAMF', 'fecha_expedicion' => '2026-09-05', 'medico' => ['nombre' => 'CLAUDIA BETANCOURT', 'registro_medico' => '43152152', 'especialidad' => 'MEDICINA GENERAL'], 'cie10_principal' => ['codigo' => 'I10X', 'descripcion' => 'HIPERTENSION ESENCIAL']],
                ['numero' => 2, 'ips' => 'METROSALUD'],
            ],
            'medicamentos' => [
                ['formula' => 1, 'descripcion' => 'Losartan 50 mg', 'forma_farmaceutica' => 'tableta', 'posologia' => '1 tableta vía oral cada 12 horas', 'duracion_dias' => 90, 'cantidad_total' => 180],
            ],
            'calidad_lectura' => 'ALTA',
        ];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($lectura)]]]]],
        ])]);

        $this->cargarOrden();
        $transcripcion = Transcripcion::sole();
        $item = $transcripcion->items()->sole();

        $this->assertSame(Transcripcion::ESTADO_LEIDA, $transcripcion->estado);
        $this->assertSame(Transcripcion::CEDULA_COINCIDE, $transcripcion->verificacion_cedula);
        $this->assertSame('CS CISAMF', $transcripcion->formulas()[0]['ips']);
        $this->assertSame('I10X', $transcripcion->formulas()[0]['cie10_principal']['codigo']);
        $this->assertCount(2, $transcripcion->formulas());

        $this->assertSame('MX377-6', $item->codigo_inventario);
        $this->assertSame(3, $item->meses);
        $this->assertEquals(60, $item->cantidad_mes);
        $this->assertSame('1 tableta vía oral cada 12 horas', $item->posologia);
        $this->assertSame(1, $item->formula); // cada medicamento sabe de qué fórmula de la imagen es

        Http::assertSent(fn ($peticion) => $peticion->hasHeader('x-goog-api-key', 'clave-gemini')
            && ! str_contains($peticion->url(), 'clave-gemini'));
    }

    public function test_si_gemini_falla_no_se_inventa_nada(): void
    {
        config(['services.transcripcion.motor' => 'gemini', 'services.gemini.key' => 'clave-gemini']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 429)]);

        $this->cargarOrden();
        $transcripcion = Transcripcion::sole();

        $this->assertSame(Transcripcion::ESTADO_FALLIDA, $transcripcion->estado);
        $this->assertStringContainsString('429', $transcripcion->error);
        $this->assertSame(0, $transcripcion->items()->count());
    }

    public function test_sin_clave_queda_fallida_con_el_motivo(): void
    {
        config(['services.google_vision.key' => null]);
        Http::fake();

        $this->cargarOrden();
        $transcripcion = Transcripcion::sole();

        $this->assertSame(Transcripcion::ESTADO_FALLIDA, $transcripcion->estado);
        $this->assertStringContainsString('GOOGLE_VISION_API_KEY', $transcripcion->error);
        Http::assertNothingSent();
    }

    public function test_alerta_cuando_difiere_la_concentracion(): void
    {
        $alertas = InterpretarFormula::alertas('Omeprazol 40 mg capsula', 'OMEPRAZOL 20 MG CAPSULA', 95);

        $this->assertSame(['Difiere concentración (Rx: 40 MG / Bodega: 20 MG)'], $alertas);
        $this->assertSame(['500 MG', '65 MG'], InterpretarFormula::concentraciones('Acetaminofen (500+65)mg tableta'));
        // Visto en la prueba de punta a punta: la fórmula dice 27,50 y la bodega 27.5.
        $this->assertTrue(InterpretarFormula::mismaConcentracion('FUROATO DE FLUTICASONA 27,50 MCG/DOSIS', 'FUROATO DE FLUTICASONA 27.5 MCG/DOSIS'));
        $this->assertSame(['100 MG'], InterpretarFormula::concentraciones('100 mg'));
    }

    public function test_la_sugerencia_prefiere_el_mismo_principio_activo(): void
    {
        $items = app(InterpretarFormula::class)->medicamentos("Pregabalina 75 mg capsula # 30\nLosartan 25 mg tableta # 30");

        // La cantidad («# 30») no le baja la similitud a un nombre idéntico.
        $this->assertSame(100, $items[0]['similitud']);

        // Losartan solo → Losartan 50 (con alerta), no la combinación con hidroclorotiazida.
        $this->assertSame('MX377-6', $items[1]['codigo_inventario']);
        $this->assertSame(['Difiere concentración (Rx: 25 MG / Bodega: 50 MG)'], $items[1]['alertas']);
    }

    public function test_cedula_no_encontrada_no_acusa_a_nadie(): void
    {
        $interpretar = app(InterpretarFormula::class);

        $this->assertSame(Transcripcion::CEDULA_NO_ENCONTRADA, $interpretar->verificarCedula('Reg. 43.152.152', '1000873458'));
        $this->assertSame(Transcripcion::CEDULA_COINCIDE, $interpretar->verificarCedula('C.C. 1 000 873 458', '1000873458'));
    }

    public function test_solo_entra_quien_tiene_el_permiso(): void
    {
        Rol::create(['nombre' => 'CONSULTA', 'permisos' => ['pacientes' => ['ver']]]);
        $this->actingAs(User::factory()->create(['roles' => ['CONSULTA']]));
        $this->get('/admin/transcripciones')->assertForbidden();

        Rol::create(['nombre' => 'TRANSCRIPCION', 'permisos' => ['transcripcion' => ['ver', 'transcribir']]]);
        $this->actingAs(User::factory()->create(['roles' => ['TRANSCRIPCION']]));
        Livewire::test(ListTranscripciones::class)->assertSuccessful();
    }
}
