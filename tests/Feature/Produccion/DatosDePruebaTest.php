<?php

namespace Tests\Feature\Produccion;

use App\Models\Paciente;
use App\Models\Ticket;
use Database\Seeders\DemoProcesoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los datos de demostración nunca llegan a la base de producción.
 */
class DatosDePruebaTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_seeder_de_demostracion_no_corre_en_produccion(): void
    {
        $this->app['env'] = 'production';

        // Ni con --force, que es lo que se usa en producción para no preguntar.
        $this->artisan('db:seed', ['--class' => DemoProcesoSeeder::class, '--force' => true])
            ->expectsOutputToContain('no corre en producción')
            ->assertSuccessful();

        $this->assertSame(0, Paciente::count());
        $this->assertSame(0, Ticket::count());
    }
}
