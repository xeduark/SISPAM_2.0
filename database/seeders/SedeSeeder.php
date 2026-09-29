<?php

namespace Database\Seeders;

use App\Models\Sede;
use Illuminate\Database\Seeder;

class SedeSeeder extends Seeder
{
    /**
     * Crea (o actualiza) las sedes base del sistema.
     */
    public function run(): void
    {
        Sede::updateOrCreate(
            ['nombre' => 'Sede Principal'],
            ['activa' => true],
        );
    }
}
