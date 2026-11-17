<?php

namespace Patronato\Persistencia\Repositorios;

use Patronato\Persistencia\Models\Presupuesto;

class PresupuestoRepositorio extends Repositorio
{
    /**
     * Presupuestos del residencial en un año, por id de categoría
     *
     * @return array<int, Presupuesto>
     */
    public function delAnio(int $anio): array
    {
        return Presupuesto::query()
            ->where('anio', $anio)
            ->get()
            ->keyBy('categoria_id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Presupuesto
    {
        return Presupuesto::query()->create($datos);
    }
}
