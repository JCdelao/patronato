<?php

namespace Patronato\Persistencia\Repositorios;

use Patronato\Persistencia\Models\GeneracionCargos;

class GeneracionCargosRepositorio
{
    /**
     * Indica si ya se generaron los cargos del período
     */
    public function existe(int $residencialId, string $periodo): bool
    {
        return GeneracionCargos::query()
            ->where('residencial_id', $residencialId)
            ->whereDate('periodo', $periodo)
            ->exists();
    }

    /**
     * Generación más reciente del período, con quien la hizo
     */
    public function ultima(int $residencialId, string $periodo): ?GeneracionCargos
    {
        return GeneracionCargos::query()
            ->with('usuario:id,name')
            ->where('residencial_id', $residencialId)
            ->whereDate('periodo', $periodo)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array{residencial_id: int, periodo: string, cargos: int, automatica: bool, user_id: int|null}  $datos
     */
    public function registrar(array $datos): GeneracionCargos
    {
        return GeneracionCargos::query()->create($datos);
    }
}
