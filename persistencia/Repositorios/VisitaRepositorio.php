<?php

namespace Patronato\Persistencia\Repositorios;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\Visita;

class VisitaRepositorio extends Repositorio
{
    /** @return Collection<int, Visita> */
    public function dentro(int $residencialId): Collection
    {
        return Visita::query()->where('residencial_id', $residencialId)
            ->whereNull('salida_en')->with('vivienda:id,codigo')
            ->orderBy('entrada_en')->orderBy('id')->get();
    }

    public function bloquear(int $residencialId, int $visitaId): Visita
    {
        return Visita::query()->where('residencial_id', $residencialId)
            ->lockForUpdate()->findOrFail($visitaId);
    }

    public function registrarSalida(int $residencialId, int $visitaId, int $guardiaId, CarbonImmutable $salida): bool
    {
        return Visita::query()->whereKey($visitaId)->where('residencial_id', $residencialId)
            ->whereNull('salida_en')->update(['salida_en' => $salida, 'guardia_salida_id' => $guardiaId]) === 1;
    }

    /** @param array<string, mixed> $datos */
    public function registrarEntrada(array $datos): Visita
    {
        return Visita::query()->create($datos);
    }
}
