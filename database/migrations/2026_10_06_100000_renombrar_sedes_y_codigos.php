<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los nombres y códigos definitivos de las sedes.
 *
 * El código pasa a ser de **tres caracteres**, porque es lo que cabe cómodo en
 * el ticket impreso de 80 mm y lo que la gente alcanza a leer de un vistazo:
 * `SP-PRP-20261005-A023`.
 *
 * ## Por qué un UPDATE y no borrar y volver a crear
 *
 * Las sedes **ya tienen usuarios, colas, ventanillas y tickets colgando**.
 * Borrarlas rompería esas llaves foráneas, y recrearlas les daría otro `id`,
 * que es justo lo que esas tablas guardan. Así que solo se les cambia el
 * nombre y el código.
 *
 * ## Por qué se buscan por su código actual y no por el nombre
 *
 * El nombre es precisamente lo que está cambiando. El código, en cambio, es
 * único y estable, así que es la única llave que sirve para encontrarlas.
 *
 * ## Los tickets viejos no se tocan
 *
 * `tickets.numero` lleva el código de la sede dentro
 * (`SP-LA30-20261003-A023`) y el módulo de entrega busca por ese número. Si
 * reescribiéramos los números, entrega dejaría de encontrar los tickets ya
 * emitidos. Los que ya existen conservan su código viejo; **solo los nuevos
 * salen con el nuevo**.
 */
return new class extends Migration
{
    /** Código actual => [nombre nuevo, código nuevo] */
    private const CAMBIOS = [
        'PPLZ' => ['PREMIUM PLAZA', 'PRP'],
        'BIC' => ['EDIFICIO BIC', 'BIC'],
        'LA30' => ['LA 30', 'L30'],
        'AVEN' => ['AVENTURA', 'AVT'],
        // No estaba en la lista de sedes reales: es la administrativa y solo
        // se le ajusta el código, que tenía cuatro caracteres.
        'PRIN' => ['Sede Principal', 'SPR'],
    ];

    /** Para volver atrás: código nuevo => [nombre viejo, código viejo] */
    private const REVERSO = [
        'PRP' => ['Premium Plaza', 'PPLZ'],
        'BIC' => ['BIC', 'BIC'],
        'L30' => ['La 30', 'LA30'],
        'AVT' => ['Centro Comercial Aventura', 'AVEN'],
        'SPR' => ['Sede Principal', 'PRIN'],
    ];

    public function up(): void
    {
        $this->aplicar(self::CAMBIOS);
    }

    public function down(): void
    {
        $this->aplicar(self::REVERSO);
    }

    /**
     * En una base nueva la tabla está vacía y esto no hace nada: las sedes las
     * crea después `SedeSeeder`, ya con los nombres y códigos nuevos. Correrlo
     * dos veces tampoco hace daño, porque la segunda vez ya no encuentra el
     * código viejo.
     *
     * @param  array<string, array{0: string, 1: string}>  $cambios
     */
    private function aplicar(array $cambios): void
    {
        foreach ($cambios as $codigoActual => [$nombre, $codigo]) {
            DB::table('sedes')
                ->where('codigo', $codigoActual)
                ->update(['nombre' => $nombre, 'codigo' => $codigo, 'updated_at' => now()]);
        }
    }
};
