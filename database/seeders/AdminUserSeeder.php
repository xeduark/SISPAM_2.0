<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Crea (o actualiza) el usuario administrador del sistema.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@sispam.com'],
            [
                'name' => 'Administrador',
                'nombre_completo' => 'Administrador del Sistema',
                'password' => Hash::make('Sispam2026*'),
                'is_admin' => true,
            ],
        );
    }
}
