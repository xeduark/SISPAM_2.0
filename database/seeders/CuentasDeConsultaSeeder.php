<?php

namespace Database\Seeders;

use App\Models\Sede;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Las cuentas compartidas de consulta, una por sede.
 *
 * Las usan varias personas de la sede, a la vez y en distintos equipos, para
 * consultar pacientes en Savia. Su rol (grupo `CONSULTA` en Authentik) solo
 * deja ver lo básico: no registran, no editan y no entran a nada más.
 *
 * El documento es el username en Authentik: tiene que escribirse igual allá.
 *
 * ## Se puede volver a correr
 *
 * Busca por documento, así que no duplica. De una cuenta que ya existe solo
 * asegura lo que la hace cuenta compartida de esa sede: la marca, que no sea
 * administradora y su sede. El nombre, el correo y si está activa son del
 * administrador y no se tocan.
 *
 * Requiere que `SedeSeeder` haya corrido antes. El rol CONSULTA lo asegura
 * aquí mismo con `RolSeeder`, que no pisa los roles que ya existen.
 */
class CuentasDeConsultaSeeder extends Seeder
{
    /** Código de la sede => documento (username en Authentik). */
    public const CUENTAS = [
        'PRP' => 'ConsultaPRP',
        'BIC' => 'ConsultaBIC',
        'L30' => 'ConsultaL30',
        'AVT' => 'ConsultaAVT',
    ];

    public function run(): void
    {
        // Sin el rol, las cuentas entrarían sin poder hacer nada.
        $this->call(RolSeeder::class);

        foreach (self::CUENTAS as $codigo => $documento) {
            $sede = Sede::where('codigo', $codigo)->first();

            if ($sede === null) {
                $this->command?->warn("No existe la sede {$codigo}: corre primero SedeSeeder. No se creó {$documento}.");

                continue;
            }

            $cuenta = User::firstOrCreate(['documento' => $documento], [
                'nombre' => 'Consulta',
                'apellido' => $sede->nombre,
                'sede_id' => $sede->getKey(),
                'activo' => true,
            ]);

            $cuenta->forceFill([
                'cuenta_compartida' => true,
                'es_administrador' => false,
                'sede_id' => $sede->getKey(),
            ])->save();

            // Fija en su sede: sin otras a dónde moverse, no hay selector.
            $cuenta->sedes()->sync([$sede->getKey()]);
        }
    }
}
