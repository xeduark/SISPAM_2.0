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
        // El código va dentro del número del ticket: SP-LA30-20261003-A023.
        $sedes = [
            'Sede Principal' => 'PRIN',
            'La 30' => 'LA30',
            'Premium Plaza' => 'PPLZ',
            'BIC' => 'BIC',
            'Centro Comercial Aventura' => 'AVEN',
        ];

        foreach ($sedes as $nombre => $codigo) {
            Sede::updateOrCreate(['nombre' => $nombre], ['codigo' => $codigo, 'activa' => true]);
        }
    }
}
