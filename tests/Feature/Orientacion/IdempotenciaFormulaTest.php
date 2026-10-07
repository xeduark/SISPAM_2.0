<?php

namespace Tests\Feature\Orientacion;

use App\Models\Cola;
use App\Models\ContadorTurno;
use App\Models\Sede;
use App\Models\Soporte;
use App\Models\Ticket;
use App\Models\Transcripcion;
use App\Models\User;
use App\Services\Orientacion\RegistrarVisita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Idempotencia de fórmulas en el diseño nuevo (RegistrarVisita).
 *
 * La llave es la ruta persistente del archivo, no el paciente: un upload nuevo
 * (ULID distinto) sí abre o suma hoja; reenviar la misma ruta no duplica.
 */
class IdempotenciaFormulaTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private User $orientador;

    private RegistrarVisita $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->sede = Sede::factory()->create(['nombre' => 'PREMIUM PLAZA', 'codigo' => 'PRP']);

        Cola::factory()->create([
            'sede_id' => $this->sede->id,
            'nombre' => 'Dispensación general',
            'prefijo' => 'A',
            'orden' => 1,
            'activa' => true,
            'atiende_alto_costo' => false,
        ]);

        $this->orientador = User::factory()->administrador()->create([
            'sede_id' => $this->sede->id,
        ]);

        $this->actingAs($this->orientador);
        $this->registrar = app(RegistrarVisita::class);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string|null>}
     */
    private function datosPaciente(string $documento = '1017234567'): array
    {
        return [
            [
                'tipo_documento' => 'CC',
                'numero_documento' => $documento,
                'primer_nombre' => 'MARIA',
                'primer_apellido' => 'GOMEZ',
                'estado_afiliacion' => 'Activo',
                'regimen' => 'SUBSIDIADO',
            ],
            [
                'telefono_movil' => '3001234567',
                'direccion' => 'KR 40 70A 23',
                'ciudad_residencia' => 'MEDELLÍN',
            ],
        ];
    }

    private function guardarRuta(string $nombre = 'formula-x.png'): string
    {
        $ruta = 'soportes/ordenes-medicas/'.$nombre;
        Storage::disk('local')->put($ruta, 'bytes-'.$nombre);

        return $ruta;
    }

    public function test_o1_paciente_sin_visita_crea_ticket_y_soporte(): void
    {
        [$atributos, $contacto] = $this->datosPaciente();
        $ruta = $this->guardarRuta('x.png');

        $resultado = $this->registrar->handle(
            $atributos,
            $contacto,
            [$ruta],
            ['prioridad' => Ticket::PRIORIDAD_NORMAL],
            $this->orientador,
        );

        $this->assertTrue($resultado->tieneTicket());
        $this->assertTrue($resultado->turnoNuevo);
        $this->assertSame(1, $resultado->ordenesGuardadas);
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, Soporte::count());
        $this->assertSame($resultado->ticket->id, Soporte::first()->ticket_id);
        $this->assertSame(1, Transcripcion::count());
    }

    public function test_o2_reenviar_misma_formula_no_duplica_ni_consume_turno(): void
    {
        [$atributos, $contacto] = $this->datosPaciente();
        $ruta = $this->guardarRuta('x.png');

        $primero = $this->registrar->handle(
            $atributos,
            $contacto,
            [$ruta],
            ['prioridad' => Ticket::PRIORIDAD_NORMAL],
            $this->orientador,
        );

        $turno = $primero->ticket->turno;
        $ultimo = (int) ContadorTurno::query()->where('sede_id', $this->sede->id)->value('ultimo');

        $segundo = $this->registrar->handle(
            $atributos,
            $contacto,
            [$ruta],
            ['prioridad' => Ticket::PRIORIDAD_NORMAL],
            $this->orientador,
        );

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, Soporte::count());
        $this->assertSame(1, Transcripcion::count());
        $this->assertSame(0, $segundo->ordenesGuardadas);
        $this->assertFalse($segundo->turnoNuevo);
        $this->assertSame($primero->ticket->id, $segundo->ticket?->id);
        $this->assertSame($turno, $segundo->ticket?->turno);
        $this->assertSame($ultimo, (int) ContadorTurno::query()->where('sede_id', $this->sede->id)->value('ultimo'));
    }

    public function test_o3_misma_visita_otra_hoja_distinta_se_suma(): void
    {
        [$atributos, $contacto] = $this->datosPaciente();
        $rutaX = $this->guardarRuta('x.png');
        $rutaY = $this->guardarRuta('y.png');

        $primero = $this->registrar->handle(
            $atributos,
            $contacto,
            [$rutaX],
            ['prioridad' => Ticket::PRIORIDAD_NORMAL],
            $this->orientador,
        );

        $segundo = $this->registrar->handle(
            $atributos,
            $contacto,
            [$rutaY],
            ['prioridad' => Ticket::PRIORIDAD_NORMAL],
            $this->orientador,
            visitaExistente: $primero->ticket,
        );

        $this->assertSame(1, Ticket::count());
        $this->assertSame(2, Soporte::count());
        $this->assertSame(2, Transcripcion::count());
        $this->assertSame(1, $segundo->ordenesGuardadas);
        $this->assertFalse($segundo->turnoNuevo);
        $this->assertSame(
            [$rutaX, $rutaY],
            Soporte::query()->orderBy('pagina')->pluck('orden_medica')->all()
        );
    }

    public function test_o4_nueva_visita_con_otra_formula_en_otro_momento_es_permitida(): void
    {
        [$atributos, $contacto] = $this->datosPaciente();
        $rutaX = $this->guardarRuta('dia1.png');
        $rutaY = $this->guardarRuta('dia2.png');

        $primero = $this->registrar->handle(
            $atributos,
            $contacto,
            [$rutaX],
            ['prioridad' => Ticket::PRIORIDAD_NORMAL],
            $this->orientador,
        );

        $primero->ticket->update([
            'estado' => Ticket::ESTADO_ENTREGADO,
            'cerrado_en' => now(),
        ]);

        $segundo = $this->registrar->handle(
            $atributos,
            $contacto,
            [$rutaY],
            ['prioridad' => Ticket::PRIORIDAD_NORMAL],
            $this->orientador,
        );

        $this->assertSame(2, Ticket::count());
        $this->assertSame(2, Soporte::count());
        $this->assertTrue($segundo->turnoNuevo);
        $this->assertNotSame($primero->ticket->id, $segundo->ticket?->id);
    }

    public function test_o5_dos_llamadas_con_la_misma_formula_solo_crean_una_vez(): void
    {
        [$atributos, $contacto] = $this->datosPaciente();
        $ruta = $this->guardarRuta('concurrente.png');

        $a = $this->registrar->handle(
            $atributos,
            $contacto,
            [$ruta],
            ['prioridad' => Ticket::PRIORIDAD_NORMAL],
            $this->orientador,
        );

        $b = $this->registrar->handle(
            $atributos,
            $contacto,
            [$ruta],
            ['prioridad' => Ticket::PRIORIDAD_NORMAL],
            $this->orientador,
        );

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, Soporte::count());
        $this->assertSame(1, Transcripcion::where('soporte_id', Soporte::first()->id)->count());
        $this->assertTrue($a->turnoNuevo);
        $this->assertFalse($b->turnoNuevo);
        $this->assertSame(1, (int) ContadorTurno::query()->where('sede_id', $this->sede->id)->value('ultimo'));
    }

    public function test_forzar_otro_ticket_con_la_misma_ruta_tampoco_duplica(): void
    {
        [$atributos, $contacto] = $this->datosPaciente();
        $ruta = $this->guardarRuta('misma.png');

        $this->registrar->handle(
            $atributos,
            $contacto,
            [$ruta],
            ['prioridad' => Ticket::PRIORIDAD_NORMAL],
            $this->orientador,
        );

        // Equivalente a «Generar de todos modos» con la misma foto todavía en el form.
        $forzado = $this->registrar->handle(
            $atributos,
            $contacto,
            [$ruta],
            ['prioridad' => Ticket::PRIORIDAD_NORMAL],
            $this->orientador,
            visitaExistente: null,
        );

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, Soporte::count());
        $this->assertSame(0, $forzado->ordenesGuardadas);
    }
}
