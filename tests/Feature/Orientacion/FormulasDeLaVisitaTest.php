<?php

namespace Tests\Feature\Orientacion;

use App\Filament\Pages\Orientacion;
use App\Filament\Resources\PacienteResource;
use App\Models\Cola;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Soporte;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Varias fotos por visita.
 *
 * Una fórmula rara vez cabe en una hoja: trae anexos, va por ambas caras o
 * son dos páginas. Cada archivo queda como un soporte propio, con su número
 * de página y colgado del mismo ticket.
 */
class FormulasDeLaVisitaTest extends TestCase
{
    use RefreshDatabase;

    private const URL_TOKEN = '*rest/token/generacion';

    private const URL_CONSULTA = '*rest/afiliado/consultar-afiliado';

    private Sede $sede;

    private User $orientador;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake('local');

        config([
            'savia.username' => '900000000',
            'savia.password' => 'clave-de-prueba',
            'savia.token' => null,
            'savia.auditar' => false,
        ]);

        $this->sede = Sede::factory()->create(['nombre' => 'PREMIUM PLAZA', 'codigo' => 'PRP']);

        Cola::factory()->create([
            'sede_id' => $this->sede->id,
            'nombre' => 'Dispensación general',
            'prefijo' => 'A',
            'orden' => 1,
            'activa' => true,
            'atiende_alto_costo' => false,
        ]);

        Rol::create(['nombre' => 'ORIENTADOR', 'permisos' => [
            'orientacion' => ['usar', 'ver_orden'],
            'tickets' => ['imprimir'],
        ]]);

        $this->orientador = User::factory()->create([
            'roles' => ['ORIENTADOR'],
            'sede_id' => $this->sede->id,
        ]);

        $this->actingAs($this->orientador);

        Http::fake([
            self::URL_TOKEN => Http::response(['access_token' => 'TOKEN-A', 'expires_in' => 3600]),
            self::URL_CONSULTA => Http::response([
                'registros' => 1,
                'codigo' => 0,
                'mensaje' => 'Afiliado encontrado',
                'afiliados' => [[
                    'tipoDocumentoAfiliado' => 'CC',
                    'documentoAfiliado' => '1017234567',
                    'primerNombreAfiliado' => 'MARIA',
                    'primerApellidoAfiliado' => 'GOMEZ',
                    'fechaNacimientoAfiliado' => '1990-05-14',
                    'estadoAfiliacion' => 'Activo',
                    'regimen' => 'SUBSIDIADO',
                    'telefonoMovil' => '3001234567',
                    'direccion' => 'KR 40 70A 23',
                    'descripcionCiudadResidencia' => 'MEDELLÍN',
                    'discapacidad' => 'NO',
                ]],
            ]),
        ]);
    }

    private function consultar(): Testable
    {
        return Livewire::test(Orientacion::class)
            ->fillForm(['tipo_documento' => 'CC', 'numero_documento' => '1017234567'], 'formularioBusqueda')
            ->call('buscar');
    }

    /**
     * @param  list<UploadedFile>  $archivos
     */
    private function generarCon(array $archivos): Testable
    {
        return $this->consultar()
            ->fillForm([
                'contacto_confirmado' => true,
                'orden_medica' => $archivos,
            ], 'formularioVisita')
            ->call('generarTicket');
    }

    /* ------------------------------------------------------------------ *
     *  Un soporte por hoja
     * ------------------------------------------------------------------ */

    public function test_tres_fotos_dejan_tres_soportes_en_la_misma_visita(): void
    {
        $this->generarCon([
            UploadedFile::fake()->image('hoja-1.jpg'),
            UploadedFile::fake()->image('hoja-2.jpg'),
            UploadedFile::fake()->image('hoja-3.jpg'),
        ])->assertHasNoFormErrors([], 'formularioVisita');

        $ticket = Ticket::sole();
        $soportes = Soporte::all();

        $this->assertCount(3, $soportes);

        // Todas cuelgan del mismo ticket: es una sola visita, no tres.
        $this->assertSame([$ticket->id], $soportes->pluck('ticket_id')->unique()->values()->all());

        // Y un solo turno consumido.
        $this->assertSame(1, Ticket::count());
    }

    public function test_cada_hoja_guarda_su_numero_de_pagina_en_orden(): void
    {
        $this->generarCon([
            UploadedFile::fake()->image('hoja-1.jpg'),
            UploadedFile::fake()->image('hoja-2.jpg'),
            UploadedFile::fake()->image('hoja-3.jpg'),
        ]);

        $this->assertSame(
            [1, 2, 3],
            Soporte::orderBy('id')->pluck('pagina')->all(),
        );
    }

    /**
     * El orden en que quedaron en pantalla es el orden de lectura, así que la
     * relación lo devuelve ya ordenado y ninguna pantalla tiene que acordarse.
     */
    public function test_la_relacion_del_ticket_devuelve_las_hojas_ordenadas(): void
    {
        $this->generarCon([
            UploadedFile::fake()->image('hoja-1.jpg'),
            UploadedFile::fake()->image('hoja-2.jpg'),
            UploadedFile::fake()->image('hoja-3.jpg'),
        ]);

        $ticket = Ticket::sole();

        // Se desordenan a propósito: la relación tiene que volver a ordenarlas.
        $ultimo = Soporte::orderByDesc('id')->first();
        $ultimo->update(['pagina' => 1]);
        Soporte::where('id', '!=', $ultimo->id)->update(['pagina' => 5]);

        $this->assertSame(
            [1, 5, 5],
            $ticket->soportes()->get()->pluck('pagina')->all(),
        );
        $this->assertSame($ultimo->id, $ticket->soportes()->first()->id);
    }

    public function test_cada_hoja_queda_guardada_en_el_disco_privado(): void
    {
        $this->generarCon([
            UploadedFile::fake()->image('hoja-1.jpg'),
            UploadedFile::fake()->image('hoja-2.jpg'),
        ]);

        foreach (Soporte::all() as $soporte) {
            Storage::disk('local')->assertExists($soporte->orden_medica);
            $this->assertStringStartsWith('soportes/ordenes-medicas/', $soporte->orden_medica);
        }
    }

    /* ------------------------------------------------------------------ *
     *  Imagen o PDF
     * ------------------------------------------------------------------ */

    public function test_guarda_el_tipo_del_archivo_para_no_tener_que_abrirlo_despues(): void
    {
        $this->generarCon([UploadedFile::fake()->image('hoja-1.jpg')]);

        $soporte = Soporte::sole();

        $this->assertNotNull($soporte->mime);
        $this->assertTrue($soporte->esImagen());
        $this->assertFalse($soporte->esPdf());
    }

    public function test_un_pdf_del_escaner_se_acepta_y_se_reconoce_como_pdf(): void
    {
        $this->generarCon([
            UploadedFile::fake()->create('formula.pdf', 200, 'application/pdf'),
        ])->assertHasNoFormErrors([], 'formularioVisita');

        $this->assertTrue(Soporte::sole()->esPdf());
    }

    /**
     * Los soportes anteriores a esta columna tienen el mime en null: hay que
     * poder decir si son PDF sin él.
     */
    public function test_sin_mime_guardado_el_tipo_se_deduce_de_la_extension(): void
    {
        $paciente = Paciente::factory()->create();

        $pdf = $paciente->soportes()->create([
            'orden_medica' => 'soportes/ordenes-medicas/vieja.pdf',
            'cargado_por' => $this->orientador->id,
        ]);

        $foto = $paciente->soportes()->create([
            'orden_medica' => 'soportes/ordenes-medicas/vieja.jpg',
            'cargado_por' => $this->orientador->id,
        ]);

        $this->assertNull($pdf->mime);
        $this->assertTrue($pdf->esPdf());
        $this->assertTrue($foto->esImagen());
    }

    /**
     * La migración entra con default 1, así que lo ya guardado queda como la
     * primera —y única— hoja de su visita.
     */
    public function test_los_soportes_que_ya_existian_quedan_en_la_pagina_uno(): void
    {
        $paciente = Paciente::factory()->create();

        $soporte = $paciente->soportes()->create([
            'orden_medica' => 'soportes/ordenes-medicas/vieja.jpg',
            'cargado_por' => $this->orientador->id,
        ]);

        $this->assertSame(1, $soporte->fresh()->pagina);
    }

    /* ------------------------------------------------------------------ *
     *  Los topes
     * ------------------------------------------------------------------ */

    public function test_una_sola_hoja_tambien_vale(): void
    {
        $this->generarCon([UploadedFile::fake()->image('hoja-unica.jpg')])
            ->assertHasNoFormErrors([], 'formularioVisita');

        $this->assertCount(1, Soporte::all());
        $this->assertSame(1, Soporte::sole()->pagina);
    }

    public function test_no_se_aceptan_mas_hojas_de_las_que_caben(): void
    {
        $demasiadas = [];

        for ($i = 1; $i <= Orientacion::MAXIMO_DE_FORMULAS + 1; $i++) {
            $demasiadas[] = UploadedFile::fake()->image("hoja-{$i}.jpg");
        }

        $this->generarCon($demasiadas)
            ->assertHasFormErrors(['orden_medica'], 'formularioVisita');

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Soporte::count());
    }

    /**
     * HEIC no está entre los tipos aceptados a propósito: pedir `image/jpeg`
     * es justo lo que hace que iOS convierta la foto al elegirla. El que
     * llegue igual se rechaza con un mensaje que dice qué hacer.
     */
    public function test_una_foto_heic_de_iphone_se_rechaza_con_un_mensaje_util(): void
    {
        $this->generarCon([
            UploadedFile::fake()->create('IMG_0042.heic', 300, 'image/heic'),
        ])->assertHasFormErrors(['orden_medica'], 'formularioVisita');

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Soporte::count());
    }

    public function test_sin_ninguna_hoja_no_se_abre_la_visita(): void
    {
        $this->consultar()
            ->fillForm(['contacto_confirmado' => true], 'formularioVisita')
            ->call('generarTicket')
            ->assertHasFormErrors(['orden_medica'], 'formularioVisita');

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Paciente::count());
    }

    /* ------------------------------------------------------------------ *
     *  Cuando no hay ticket
     * ------------------------------------------------------------------ */

    /**
     * Si la sede no está configurada se pierde el turno, pero no la fórmula:
     * las hojas quedan guardadas con su página, esperando el ticket.
     */
    public function test_sin_colas_las_hojas_igual_se_guardan_sin_ticket(): void
    {
        Cola::query()->update(['activa' => false]);

        $this->generarCon([
            UploadedFile::fake()->image('hoja-1.jpg'),
            UploadedFile::fake()->image('hoja-2.jpg'),
        ]);

        $soportes = Soporte::orderBy('id')->get();

        $this->assertCount(2, $soportes);
        $this->assertSame([1, 2], $soportes->pluck('pagina')->all());
        $this->assertSame([null, null], $soportes->pluck('ticket_id')->all());
        $this->assertSame(0, Ticket::count());
    }

    /* ------------------------------------------------------------------ *
     *  La normalización del campo
     * ------------------------------------------------------------------ */

    public function test_la_normalizacion_entiende_las_tres_formas_del_fileupload(): void
    {
        $recurso = PacienteResource::class;

        // Una ruta suelta.
        $suelta = ['orden_medica' => 'ruta/a.jpg'];
        $this->assertSame(['ruta/a.jpg'], $recurso::separarOrdenesMedicas($suelta));

        // El [uuid => ruta] de cuando todavía no se deshidrató.
        $sinDeshidratar = ['orden_medica' => ['uuid-1' => 'ruta/a.jpg', 'uuid-2' => 'ruta/b.jpg']];
        $this->assertSame(['ruta/a.jpg', 'ruta/b.jpg'], $recurso::separarOrdenesMedicas($sinDeshidratar));

        // La lista ordenada de `multiple()`.
        $lista = ['orden_medica' => ['ruta/a.jpg', 'ruta/b.jpg']];
        $this->assertSame(['ruta/a.jpg', 'ruta/b.jpg'], $recurso::separarOrdenesMedicas($lista));

        // Sin nada.
        $vacia = ['orden_medica' => null];
        $this->assertSame([], $recurso::separarOrdenesMedicas($vacia));
    }
}
