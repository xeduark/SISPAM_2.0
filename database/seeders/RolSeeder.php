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
        // Genera el ticket al registrar la visita, así que tiene que poder
        // imprimírselo al paciente. No lleva `tickets.ver`: no entra al
        // listado de Tickets ni ve los medicamentos de nadie.
        Rol::firstOrCreate(['nombre' => 'ORIENTADOR'], [
            'permisos' => [
                'pacientes' => ['ver', 'crear', 'editar'],
                'consultar_paciente' => ['ver'],
                'orientacion' => ['usar', 'ver_orden'],
                'tickets' => ['imprimir'],
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
