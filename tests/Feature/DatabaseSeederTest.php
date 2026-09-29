<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_la_sede_principal_y_el_administrador(): void
    {
        $this->seed();

        $admin = User::where('documento', '1000000000')->firstOrFail();

        $this->assertSame('Administrador del Sistema', $admin->nombre_completo);
        $this->assertSame('Administrador del Sistema', $admin->getFilamentName());
        $this->assertSame('Sede Principal', $admin->sede->nombre);
        $this->assertTrue($admin->activo);
        $this->assertTrue(Hash::check('Sispam2026*', $admin->password));
    }
}
