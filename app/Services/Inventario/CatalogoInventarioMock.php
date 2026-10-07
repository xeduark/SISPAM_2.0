<?php

namespace App\Services\Inventario;

use App\Contracts\Inventario\CatalogoInventarioInterface;
use App\Contracts\Inventario\Coincidencia;
use App\Services\Transcripcion\InterpretarFormula;

/**
 * Catálogo de prueba mientras no exista el inventario. Los códigos MX… son
 * los del sistema anterior; los MOCK-… son inventados y no existen en bodega.
 */
class CatalogoInventarioMock implements CatalogoInventarioInterface
{
    /** codigo => [agrupador, nombre] */
    private const PRODUCTOS = [
        'MX804-1' => ['MX804', 'ESOMEPRAZOL 20 MG TABLETAS DE LIBERACION RETARDADA (PROCAPS) (INS)'],
        'MX1093-1' => ['MX1093', 'EZETIMIBA 10 MG + ROSUVASTATINA 40 MG TABLETAS (ROSUVINA E) (LEGRAND) (REG) (INS)'],
        'MX377-6' => ['MX377', 'LOSARTAN 50 MG TABLETA (GENFAR) (INS)'],
        'MOCK-1' => ['MOCK', 'ESOMEPRAZOL 40 MG CAPSULA DURA'],
        'MOCK-2' => ['MOCK', 'LEVOTIROXINA SODICA 25 MCG TABLETA'],
        'MOCK-3' => ['MOCK', 'SALBUTAMOL 100 MCG/DOSIS INHALADOR X 200 DOSIS'],
        'MOCK-4' => ['MOCK', 'ACETAMINOFEN 500 MG + CAFEINA 65 MG TABLETA'],
        'MOCK-5' => ['MOCK', 'ATORVASTATINA 40 MG TABLETA RECUBIERTA'],
        'MOCK-6' => ['MOCK', 'LOSARTAN 100 MG + HIDROCLOROTIAZIDA 25 MG TABLETA'],
        'MOCK-7' => ['MOCK', 'PREGABALINA 75 MG CAPSULA'],
        'MOCK-8' => ['MOCK', 'PREGABALINA 150 MG CAPSULA'],
        'MOCK-9' => ['MOCK', 'ETORICOXIB 90 MG TABLETA'],
        'MOCK-10' => ['MOCK', 'OMEPRAZOL 20 MG CAPSULA'],
        'MOCK-11' => ['MOCK', 'METFORMINA 850 MG TABLETA'],
    ];

    public function buscar(string $prescrito, int $limite = 5): array
    {
        return collect(self::PRODUCTOS)
            ->map(fn (array $p, string $codigo) => new Coincidencia(
                codigo: $codigo,
                agrupador: $p[0],
                nombre: $p[1],
                similitud: InterpretarFormula::similitud($prescrito, $p[1]),
            ))
            // A igual nombre, gana el de la misma concentración.
            ->sortByDesc(fn (Coincidencia $c) => $c->similitud - (InterpretarFormula::mismaConcentracion($prescrito, $c->nombre) ? 0 : 20))
            ->take($limite)
            ->values()
            ->all();
    }
}
