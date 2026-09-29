<?php

namespace Database\Seeders;

use App\Models\Sede;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Crea (o actualiza) el usuario administrador del sistema.
     * Requiere que SedeSeeder haya corrido antes.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['documento' => '1000000000'],
            [
                'nombre' => 'Administrador',
                'apellido' => 'del Sistema',
                'email' => 'admin@sispam.com',
                'password' => Hash::make('Sispam2026*'),
                'sede_id' => Sede::where('nombre', 'Sede Principal')->value('id'),
                'activo' => true,
            ],
        );
    }
}
