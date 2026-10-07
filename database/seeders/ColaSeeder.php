<?php

namespace Database\Seeders;

use App\Models\Cola;
use App\Models\Sede;
use App\Models\Ventanilla;
use Illuminate\Database\Seeder;

/**
 * Colas y ventanillas base de cada sede.
 *
 * Se puede volver a correr: usa `firstOrCreate`, así que no pisa lo que el
 * administrador haya ajustado ni duplica nada.
 */
class ColaSeeder extends Seeder
{
    /** Dos colas por sede: la general y la de alto costo u oncológicos. */
    private const COLAS = [
        ['nombre' => 'Dispensación general', 'prefijo' => 'A', 'orden' => 1,
            'descripcion' => 'Atención de fórmulas corrientes.'],
        ['nombre' => 'Alto costo y oncológicos', 'prefijo' => 'B', 'orden' => 2, 'atiende_alto_costo' => true,
            'descripcion' => 'Pacientes o medicamentos marcados como de alto costo u oncológicos.'],
    ];

    private const VENTANILLAS = ['Ventanilla 1', 'Ventanilla 2'];

    public function run(): void
    {
        foreach (Sede::all() as $sede) {
            foreach (self::COLAS as $cola) {
                Cola::firstOrCreate(
                    ['sede_id' => $sede->id, 'prefijo' => $cola['prefijo']],
                    [
                        'nombre' => $cola['nombre'],
                        'descripcion' => $cola['descripcion'],
                        'orden' => $cola['orden'],
                        'atiende_alto_costo' => $cola['atiende_alto_costo'] ?? false,
                        'activa' => true,
                    ],
                );
            }

            foreach (self::VENTANILLAS as $indice => $nombre) {
                Ventanilla::firstOrCreate(
                    ['sede_id' => $sede->id, 'nombre' => $nombre],
                    ['activa' => true, 'orden' => $indice + 1],
                );
            }
        }
    }
}
