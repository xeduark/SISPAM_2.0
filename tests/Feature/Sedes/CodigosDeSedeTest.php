<?php

namespace Tests\Feature\Sedes;

use App\Models\Cola;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Ventanilla;
use App\Services\Tickets\GenerarTicket;
use Database\Seeders\SedeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los códigos de tres caracteres y la sede nombrada siempre con el suyo.
 */
class CodigosDeSedeTest extends TestCase
{
    use RefreshDatabase;

    /** La migración, para poder correrla sobre datos de prueba. */
    private function migracion(): object
    {
        return require database_path('migrations/2026_10_06_100000_renombrar_sedes_y_codigos.php');
    }

    /* ------------------------------------------------------------------ *
     *  La migración de los códigos
     * ------------------------------------------------------------------ */

    public function test_renombra_las_sedes_y_les_pone_el_codigo_de_tres(): void
    {
        $viejas = [
            'PPLZ' => ['Premium Plaza', 'PREMIUM PLAZA', 'PRP'],
            'BIC' => ['BIC', 'EDIFICIO BIC', 'BIC'],
            'LA30' => ['La 30', 'LA 30', 'L30'],
            'AVEN' => ['Centro Comercial Aventura', 'AVENTURA', 'AVT'],
            'PRIN' => ['Sede Principal', 'Sede Principal', 'SPR'],
        ];

        $creadas = [];
        foreach ($viejas as $codigoViejo => [$nombreViejo]) {
            $creadas[$codigoViejo] = Sede::factory()->create([
                'nombre' => $nombreViejo,
                'codigo' => $codigoViejo,
            ]);
        }

        $this->migracion()->up();

        foreach ($viejas as $codigoViejo => [, $nombreNuevo, $codigoNuevo]) {
            $sede = $creadas[$codigoViejo]->fresh();

            $this->assertSame($nombreNuevo, $sede->nombre);
            $this->assertSame($codigoNuevo, $sede->codigo);
            $this->assertSame(3, mb_strlen($sede->codigo));
        }
    }

    /**
     * Lo que de verdad importa: las sedes tienen usuarios, colas, ventanillas
     * y tickets colgando. Si la migración las borrara y las recreara, todas
     * esas llaves foráneas apuntarían a un id que ya no existe.
     */
    public function test_conserva_el_id_y_todo_lo_que_cuelga_de_la_sede(): void
    {
        $sede = Sede::factory()->create(['nombre' => 'La 30', 'codigo' => 'LA30']);
        $idOriginal = $sede->id;

        $usuario = User::factory()->create(['sede_id' => $sede->id]);
        $cola = Cola::factory()->create(['sede_id' => $sede->id]);
        $ventanilla = Ventanilla::factory()->create(['sede_id' => $sede->id]);
        $ticket = Ticket::factory()->create(['sede_id' => $sede->id, 'cola_id' => $cola->id]);

        $this->migracion()->up();

        $this->assertSame($idOriginal, $sede->fresh()->id);
        $this->assertSame($idOriginal, $usuario->fresh()->sede_id);
        $this->assertSame($idOriginal, $cola->fresh()->sede_id);
        $this->assertSame($idOriginal, $ventanilla->fresh()->sede_id);
        $this->assertSame($idOriginal, $ticket->fresh()->sede_id);
    }

    /**
     * El número lleva el código dentro y el módulo de entrega busca por él. Si
     * la migración reescribiera los números, entrega dejaría de encontrar los
     * tickets ya emitidos.
     */
    public function test_los_tickets_ya_emitidos_conservan_su_numero_viejo(): void
    {
        $sede = Sede::factory()->create(['nombre' => 'La 30', 'codigo' => 'LA30']);
        $ticket = Ticket::factory()->create([
            'sede_id' => $sede->id,
            'numero' => 'SP-LA30-20261003-A023',
        ]);

        $this->migracion()->up();

        $this->assertSame('SP-LA30-20261003-A023', $ticket->fresh()->numero);
    }

    public function test_correrla_dos_veces_no_hace_dano(): void
    {
        $sede = Sede::factory()->create(['nombre' => 'Premium Plaza', 'codigo' => 'PPLZ']);

        $this->migracion()->up();
        $this->migracion()->up();

        $this->assertSame('PREMIUM PLAZA', $sede->fresh()->nombre);
        $this->assertSame('PRP', $sede->fresh()->codigo);
    }

    public function test_en_una_base_sin_sedes_no_revienta(): void
    {
        $this->migracion()->up();

        $this->assertSame(0, Sede::count());
    }

    public function test_se_puede_volver_atras(): void
    {
        $sede = Sede::factory()->create(['nombre' => 'Centro Comercial Aventura', 'codigo' => 'AVEN']);

        $this->migracion()->up();
        $this->migracion()->down();

        $this->assertSame('Centro Comercial Aventura', $sede->fresh()->nombre);
        $this->assertSame('AVEN', $sede->fresh()->codigo);
    }

    /* ------------------------------------------------------------------ *
     *  El seeder
     * ------------------------------------------------------------------ */

    public function test_el_seeder_deja_las_cinco_sedes_con_su_codigo(): void
    {
        $this->seed(SedeSeeder::class);

        $this->assertSame([
            'AVT' => 'AVENTURA',
            'BIC' => 'EDIFICIO BIC',
            'L30' => 'LA 30',
            'PRP' => 'PREMIUM PLAZA',
            'SPR' => 'Sede Principal',
        ], Sede::orderBy('codigo')->pluck('nombre', 'codigo')->all());
    }

    /**
     * Busca por código y no por nombre, porque los nombres cambiaron. Si
     * buscara por nombre, no encontraría la sede que ya existe: crearía otra y
     * chocaría contra el índice único del código.
     */
    public function test_el_seeder_encuentra_la_sede_renombrada_en_vez_de_duplicarla(): void
    {
        // Una sede que todavía tiene el nombre viejo pero ya el código nuevo.
        $sede = Sede::factory()->create(['nombre' => 'Premium Plaza', 'codigo' => 'PRP']);

        $this->seed(SedeSeeder::class);

        $this->assertSame(5, Sede::count());
        $this->assertSame('PREMIUM PLAZA', $sede->fresh()->nombre);
        $this->assertSame($sede->id, Sede::where('codigo', 'PRP')->value('id'));
    }

    public function test_el_seeder_se_puede_repetir(): void
    {
        $this->seed(SedeSeeder::class);
        $this->seed(SedeSeeder::class);

        $this->assertSame(5, Sede::count());
    }

    /* ------------------------------------------------------------------ *
     *  La sede siempre con su código
     * ------------------------------------------------------------------ */

    public function test_la_etiqueta_lleva_el_nombre_y_el_codigo(): void
    {
        $sede = Sede::factory()->create(['nombre' => 'PREMIUM PLAZA', 'codigo' => 'PRP']);

        $this->assertSame('PREMIUM PLAZA (PRP)', $sede->etiqueta);
    }

    public function test_sin_codigo_la_etiqueta_es_solo_el_nombre(): void
    {
        $sede = Sede::factory()->create(['nombre' => 'La 7', 'codigo' => null]);

        $this->assertSame('La 7', $sede->etiqueta);
    }

    public function test_las_opciones_de_los_selectores_llevan_el_codigo(): void
    {
        $sede = Sede::factory()->create(['nombre' => 'AVENTURA', 'codigo' => 'AVT']);

        $this->assertSame(['AVENTURA (AVT)'], array_values(Sede::opciones()));
        $this->assertSame('AVENTURA (AVT)', Sede::opciones()[$sede->id]);
    }

    /* ------------------------------------------------------------------ *
     *  El número del ticket con el código nuevo
     * ------------------------------------------------------------------ */

    public function test_los_tickets_nuevos_salen_con_el_codigo_de_tres(): void
    {
        $sede = Sede::factory()->create(['nombre' => 'PREMIUM PLAZA', 'codigo' => 'PRP']);
        Cola::factory()->create(['sede_id' => $sede->id, 'prefijo' => 'A', 'atiende_alto_costo' => false]);

        $ticket = app(GenerarTicket::class)->handle(
            Paciente::factory()->create(),
            $sede,
            User::factory()->create(['sede_id' => $sede->id]),
        );

        $this->assertSame('TK-PRP-'.now()->format('ymd').'-0001', $ticket->numero);
        $this->assertSame('0001', $ticket->turno);
    }
}
