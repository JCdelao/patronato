<?php

namespace Patronato\Persistencia\Repositorios;

use Patronato\Persistencia\Models\Cargo;
use Patronato\Persistencia\Models\Vivienda;

class CargoRepositorio
{
    /**
     * Indica si el concepto tiene cargos, desde un período y para un tipo de vivienda o una vivienda si se indican
     */
    public function existenDe(int $conceptoId, ?string $desde = null, ?int $tipoViviendaId = null, ?int $viviendaId = null): bool
    {
        return Cargo::query()
            ->where('concepto_id', $conceptoId)
            ->when($desde, fn ($query, string $fecha) => $query->whereDate('periodo', '>=', $fecha))
            ->when($viviendaId, fn ($query, int $id) => $query->where('vivienda_id', $id))
            ->when($tipoViviendaId, fn ($query, int $tipoId) => $query->whereIn(
                'vivienda_id',
                Vivienda::query()->where('tipo_vivienda_id', $tipoId)->select('id'),
            ))
            ->exists();
    }
}
