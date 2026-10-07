<?php

namespace Tests\Feature\Tickets;

use App\Models\Cola;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El ticket impreso: quién puede sacarlo y qué sale —y qué no— en el papel.
 */
class ImpresionDeTicketTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private Paciente $paciente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sede = Sede::factory()->create(['nombre' => 'PREMIUM PLAZA', 'codigo' => 'PRP']);

        // El paciente del mockup. Su nombre sí sale en el papel; su documento
        // no, y las pruebas de abajo lo vigilan.
        $this->paciente = Paciente::factory()->create([
            'tipo_documento' => 'CC',
            'numero_documento' => '1017234567',
            'primer_nombre' => 'LUZ',
            'segundo_nombre' => 'MARINA',
            'primer_apellido' => 'ARANGO',
            'segundo_apellido' => 'GARZON',
        ]);
    }

    private function ticket(array $extra = [], ?Sede $sede = null): Ticket
    {
        $sede ??= $this->sede;

        // Una sola cola «A» por sede: el índice único no deja dos, y varias
        // pruebas piden más de un ticket.
        $cola = Cola::firstOrCreate(
            ['sede_id' => $sede->id, 'prefijo' => 'A'],
            ['nombre' => 'Dispensación general', 'activa' => true, 'orden' => 1],
        );

        $turno = $extra['turno'] ?? '0060';

        return Ticket::factory()->create([
            'sede_id' => $sede->id,
            'cola_id' => $cola->id,
            'paciente_id' => $this->paciente->id,
            'numero' => 'TK-'.$sede->codigo.'-'.now()->format('ymd').'-'.str_replace('-', '', $turno),
            'turno' => $turno,
            ...$extra,
        ]);
    }

    /** Un usuario con las acciones que se le pasen sobre el módulo tickets. */
    private function usuario(array $acciones, ?Sede $sede = null): User
    {
        Rol::updateOrCreate(['nombre' => 'PRUEBA'], ['permisos' => ['tickets' => $acciones]]);

        return User::factory()->create([
            'sede_id' => ($sede ?? $this->sede)->id,
            'roles' => ['PRUEBA'],
            'es_administrador' => false,
        ]);
    }

    /* ------------------------------------------------------------------ *
     *  Permisos
     * ------------------------------------------------------------------ */

    /**
     * El orientador genera el ticket pero **no tiene `tickets.ver`**: no entra
     * al listado ni ve los medicamentos de nadie. Si imprimir dependiera de
     * `ver`, no podría darle el papel al paciente que acaba de registrar.
     */
    public function test_con_solo_imprimir_y_sin_ver_se_puede_imprimir(): void
    {
        $this->actingAs($this->usuario(['imprimir']));

        $this->get(route('tickets.imprimir', $this->ticket()))
            ->assertOk()
            ->assertSee('0060');
    }

    public function test_con_solo_ver_no_se_puede_imprimir(): void
    {
        $this->actingAs($this->usuario(['ver']));

        $this->get(route('tickets.imprimir', $this->ticket()))->assertForbidden();
    }

    public function test_sin_ningun_permiso_de_tickets_no_se_puede_imprimir(): void
    {
        $this->actingAs(User::factory()->create([
            'sede_id' => $this->sede->id,
            'roles' => [],
            'es_administrador' => false,
        ]));

        $this->get(route('tickets.imprimir', $this->ticket()))->assertForbidden();
    }

    public function test_sin_sesion_no_hay_ticket(): void
    {
        $this->get(route('tickets.imprimir', $this->ticket()))->assertRedirect();
    }

    /* ------------------------------------------------------------------ *
     *  Cada quien imprime lo de su sede
     * ------------------------------------------------------------------ */

    public function test_no_se_imprime_un_ticket_de_otra_sede(): void
    {
        $otra = Sede::factory()->create(['nombre' => 'AVENTURA', 'codigo' => 'AVT']);

        $this->actingAs($this->usuario(['imprimir']));

        $this->get(route('tickets.imprimir', $this->ticket(sede: $otra)))->assertForbidden();
    }

    public function test_el_administrador_imprime_el_de_cualquier_sede(): void
    {
        $otra = Sede::factory()->create(['nombre' => 'AVENTURA', 'codigo' => 'AVT']);

        $this->actingAs(User::factory()->administrador()->create(['sede_id' => $this->sede->id]));

        $this->get(route('tickets.imprimir', $this->ticket(sede: $otra)))->assertOk();
    }

    /* ------------------------------------------------------------------ *
     *  Qué sale en el papel
     * ------------------------------------------------------------------ */

    public function test_el_papel_lleva_todo_lo_del_mockup(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->usuario(['imprimir']));

        $this->get(route('tickets.imprimir', $ticket))
            ->assertOk()
            // La sede arriba, siempre con su código.
            ->assertSee('SEDE: PREMIUM PLAZA (PRP)')
            // El turno en grande y el número completo.
            ->assertSee('0060')
            ->assertSee('TK-PRP-'.now()->format('ymd').'-0060')
            // El paciente, para que reconozca su papel.
            ->assertSee('PACIENTE')
            ->assertSee('LUZ MARINA ARANGO GARZON')
            // Fecha, hora y prioridad.
            ->assertSee(now()->format('d/m/Y'))
            ->assertSee('PRIORIDAD: NORMAL')
            // La caja del ingreso.
            ->assertSee('ALERTA DE INGRESO')
            ->assertSee('PREMIUM PLAZA');
    }

    public function test_el_preferencial_lo_dice_pero_no_dice_por_que(): void
    {
        $ticket = $this->ticket([
            'prioridad' => Ticket::PRIORIDAD_PREFERENCIAL,
            'motivo_prioridad' => 'discapacidad',
        ]);

        $this->actingAs($this->usuario(['imprimir']));

        $this->get(route('tickets.imprimir', $ticket))
            ->assertOk()
            ->assertSee('PRIORIDAD: PREFERENCIAL')
            // El motivo es un dato de salud: el papel dice el qué, no el porqué.
            ->assertDontSee('discapacidad', escape: false)
            ->assertDontSee('Discapacidad', escape: false);
    }

    public function test_el_normal_sale_como_normal(): void
    {
        $this->actingAs($this->usuario(['imprimir']));

        $this->get(route('tickets.imprimir', $this->ticket()))
            ->assertOk()
            ->assertSee('PRIORIDAD: NORMAL')
            ->assertDontSee('PREFERENCIAL');
    }

    /**
     * La prueba que de verdad importa.
     *
     * El nombre del paciente sí va —tiene que reconocer su papel—, pero lo
     * clínico no: un ticket se queda en un mostrador y lo recoge cualquiera.
     * Que diga a nombre de quién es no cuenta nada de su salud; que diga a qué
     * cola va, sí.
     */
    public function test_en_el_papel_no_hay_nada_clinico(): void
    {
        $cola = Cola::factory()->create([
            'sede_id' => $this->sede->id,
            'prefijo' => 'B',
            'nombre' => 'Alto costo y oncológicos',
            'atiende_alto_costo' => true,
        ]);

        $ticket = $this->ticket(['cola_id' => $cola->id, 'alto_costo' => true]);
        $ticket->items()->create([
            'codigo' => 'MED-001',
            'nombre' => 'IMATINIB 400 MG TABLETA',
            'cantidad' => 30,
            'unidad' => 'TAB',
        ]);

        $this->actingAs($this->usuario(['imprimir']));

        $this->get(route('tickets.imprimir', $ticket))
            ->assertOk()
            // Su documento no hace falta para reconocer el papel.
            ->assertDontSee('1017234567')
            // Ni los medicamentos.
            ->assertDontSee('IMATINIB')
            ->assertDontSee('MED-001')
            // Ni el nombre de la cola, que delataría a qué va.
            ->assertDontSee('Alto costo')
            ->assertDontSee('oncológicos', escape: false);
    }

    /* ------------------------------------------------------------------ *
     *  El turno ocupa todo el ancho
     * ------------------------------------------------------------------ */

    /**
     * Es lo único que el paciente mira de lejos: tiene que ser lo más grande
     * que quepa en los 80 mm, no un tamaño fijo que deje el papel a medias.
     */
    public function test_el_turno_sale_lo_mas_grande_que_cabe(): void
    {
        $this->actingAs($this->usuario(['imprimir']));

        // «0060» son cuatro caracteres: 187 / (4 × 0,6) = 77 pt.
        $this->get(route('tickets.imprimir', $this->ticket()))
            ->assertOk()
            ->assertSee('font-size: 77pt', escape: false);
    }

    /**
     * Un turno más largo se achica para no salirse del papel, y uno más corto
     * crece. Con un tamaño fijo, uno de los dos quedaría mal.
     */
    public function test_el_tamano_del_turno_se_ajusta_a_lo_que_mida(): void
    {
        $this->actingAs($this->usuario(['imprimir']));

        // Pasado el 9999 el turno crece a cinco cifras: 187 / (5 × 0,6) = 62 pt.
        $this->get(route('tickets.imprimir', $this->ticket(['turno' => '10000'])))
            ->assertOk()
            ->assertSee('font-size: 62pt', escape: false);

        // Y un ticket viejo, con el prefijo que llevaban antes: seis
        // caracteres, 187 / (6 × 0,6) = 51 pt. Se siguen pudiendo reimprimir.
        $this->get(route('tickets.imprimir', $this->ticket(['turno' => 'A-0060'])))
            ->assertOk()
            ->assertSee('font-size: 51pt', escape: false);
    }

    /* ------------------------------------------------------------------ *
     *  La impresión en sí
     * ------------------------------------------------------------------ */

    public function test_el_papel_esta_armado_para_ochenta_milimetros_y_abre_el_dialogo(): void
    {
        $this->actingAs($this->usuario(['imprimir']));

        $this->get(route('tickets.imprimir', $this->ticket()))
            ->assertOk()
            ->assertSee('size: 80mm auto', escape: false)
            ->assertSee('window.print()', escape: false)
            // Monospace, como la térmica.
            ->assertSee('Courier New', escape: false)
            // Sin build de Tailwind: el CSS va embebido.
            ->assertDontSee('<link rel="stylesheet"', escape: false);
    }
}
