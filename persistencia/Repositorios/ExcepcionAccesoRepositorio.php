<?php

namespace Patronato\Persistencia\Repositorios;

use Carbon\CarbonImmutable;
use Patronato\Persistencia\Models\ExcepcionAcceso;

class ExcepcionAccesoRepositorio
{
    public function vigente(int $residencialId, int $viviendaId, CarbonImmutable $ahora): bool
    {
        return ExcepcionAcceso::query()->where('residencial_id', $residencialId)
            ->where('vivienda_id', $viviendaId)->where('vence_en', '>', $ahora)->exists();
    }

    public function registrar(int $residencialId, int $viviendaId, int $usuarioId, string $motivo, CarbonImmutable $vence): ExcepcionAcceso
    {
        return ExcepcionAcceso::query()->create([
            'residencial_id' => $residencialId,
            'vivienda_id' => $viviendaId,
            'autorizado_por' => $usuarioId,
            'motivo' => $motivo,
            'vence_en' => $vence,
        ]);
    }
}
