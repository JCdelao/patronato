<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\MedioEntrada;

class MedioEntradaRepositorio extends Repositorio
{
    /** @return Collection<int, MedioEntrada> */
    public function listado(int $residencialId, bool $soloActivos): Collection
    {
        return MedioEntrada::query()
            ->where('residencial_id', $residencialId)
            ->when($soloActivos, fn ($query) => $query->where('activo', true))
            ->orderBy('nombre')
            ->get();
    }

    public function bloquearActivo(int $residencialId, int $id): ?MedioEntrada
    {
        return MedioEntrada::query()->where('residencial_id', $residencialId)
            ->where('activo', true)->lockForUpdate()->find($id);
    }

    /** @param array<string, mixed> $datos */
    public function crear(array $datos): MedioEntrada
    {
        return MedioEntrada::query()->create($datos);
    }
}
