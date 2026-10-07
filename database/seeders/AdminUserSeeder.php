<?php

namespace Database\Seeders;

use App\Models\Sede;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Crea (o actualiza) el usuario administrador del sistema.
     * Requiere que SedeSeeder haya corrido antes.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['documento' => 'AdminSispam'],
            [
                'nombre' => 'Administrador',
                'apellido' => 'del Sistema',
                'email' => 'admin@sispam.com',
                'sede_id' => Sede::where('nombre', 'Sede Principal')->value('id'),
                'activo' => true,
                'es_administrador' => true,
            ],
        );
    }
}
