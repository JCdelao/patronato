<?php

namespace Patronato\Persistencia\Repositorios;

use Patronato\Persistencia\Models\Cargo;

class CargoRepositorio
{
    /**
     * Indica si el concepto tiene cargos, desde un período si se indica
     */
    public function existenDe(int $conceptoId, ?string $desde = null): bool
    {
        return Cargo::query()
            ->where('concepto_id', $conceptoId)
            ->when($desde, fn ($query, string $fecha) => $query->whereDate('periodo', '>=', $fecha))
            ->exists();
    }
}
