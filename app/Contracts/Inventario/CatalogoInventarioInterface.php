<?php

namespace App\Contracts\Inventario;

/**
 * El catálogo de productos del inventario.
 *
 * El inventario todavía no existe: mientras tanto responde
 * `CatalogoInventarioMock`. Cuando llegue el real, solo cambia el binding en
 * AppServiceProvider.
 */
interface CatalogoInventarioInterface
{
    /**
     * Los productos más parecidos a lo que dice la fórmula, el mejor primero.
     *
     * @return list<Coincidencia>
     */
    public function buscar(string $prescrito, int $limite = 5): array;
}
