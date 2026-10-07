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
        // «Sede Principal» queda como sede administrativa; las demás son las reales.
        // La 7 entra más adelante.
        // El código va dentro del número del ticket: SP-PRP-20261005-A023.
        $sedes = [
            'SPR' => 'Sede Principal',
            'L30' => 'LA 30',
            'PRP' => 'PREMIUM PLAZA',
            'BIC' => 'EDIFICIO BIC',
            'AVT' => 'AVENTURA',
        ];

        /*
         * Se busca por **código**, no por nombre.
         *
         * Los nombres cambiaron (`Premium Plaza` → `PREMIUM PLAZA`), así que
         * buscar por nombre no encontraría la sede que ya existe: crearía una
         * nueva y chocaría contra el índice único del código. El código, en
         * cambio, es estable y único: es la llave que de verdad identifica la
         * sede.
         */
        foreach ($sedes as $codigo => $nombre) {
            Sede::updateOrCreate(['codigo' => $codigo], ['nombre' => $nombre, 'activa' => true]);
        }
    }
}
