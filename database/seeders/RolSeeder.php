<?php

namespace Database\Seeders;

use App\Models\Rol;
use Illuminate\Database\Seeder;

class RolSeeder extends Seeder
{
    /**
     * Roles base de la matriz de permisos. El nombre debe existir como grupo en Authentik.
     * Solo se crean si faltan: los permisos que el administrador ajuste no se pisan.
     */
    public function run(): void
    {
        Rol::firstOrCreate(['nombre' => 'ORIENTADOR'], [
            'permisos' => [
                'pacientes' => ['ver', 'crear', 'editar'],
                'consultar_paciente' => ['ver'],
                'orientacion' => ['usar', 'ver_orden'],
            ],
        ]);

        // Quien atiende en la ventanilla también llama el turno.
        Rol::firstOrCreate(['nombre' => 'DISPENSADOR'], [
            'permisos' => [
                'entrega' => ['ver', 'atender', 'domicilio', 'reportes'],
                'pacientes' => ['ver'],
                'tickets' => ['ver'],
                'turnos' => ['ver', 'llamar', 'ausente'],
            ],
        ]);
    }
}
