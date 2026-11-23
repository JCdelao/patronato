<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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

    public function bloquear(int $residencialId, int $id): MedioEntrada
    {
        return MedioEntrada::query()->where('residencial_id', $residencialId)
            ->lockForUpdate()->findOrFail($id);
    }

    private function tieneReferencias(MedioEntrada $medio): bool
    {
        foreach (['visitas', 'preautorizaciones'] as $tabla) {
            if (DB::table($tabla)->where('residencial_id', $medio->residencial_id)
                ->where('medio_entrada_id', $medio->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function eliminarSiNoTieneReferencias(MedioEntrada $medio): bool
    {
        if ($this->tieneReferencias($medio)) {
            return false;
        }

        try {
            // Revierte solo el DELETE si PostgreSQL rechaza la clave foránea.
            return DB::transaction(fn (): bool => $medio->delete() === true);
        } catch (QueryException $error) {
            // La clave foránea protege también las referencias agregadas desde otros flujos.
            $sqlState = $error->errorInfo[0] ?? null;
            $codigo = (int) ($error->errorInfo[1] ?? 0);
            if ($sqlState === '23503' || ($sqlState === '23000' && ($codigo === 1451
                || str_contains($error->errorInfo[2] ?? '', 'FOREIGN KEY constraint failed')))) {
                return false;
            }

            throw $error;
        }
    }

    /** @param array<string, mixed> $datos */
    public function crear(array $datos): MedioEntrada
    {
        return MedioEntrada::query()->create($datos);
    }
}
