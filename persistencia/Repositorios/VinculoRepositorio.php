<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\ViviendaResidente;

/**
 * Relación de cada residente con las viviendas donde vive o vivió
 */
class VinculoRepositorio extends Repositorio
{
    /**
     * Vínculos vigentes de un residente, en una vivienda o en todas
     *
     * @return Collection<int, ViviendaResidente>
     */
    public function vigentesDe(int $residenteId, ?int $viviendaId = null): Collection
    {
        return $this->vigentes()
            ->where('residente_id', $residenteId)
            ->when($viviendaId !== null, fn (Builder $query) => $query->where('vivienda_id', $viviendaId))
            ->orderBy('id')
            ->get();
    }

    public function viveEn(int $residenteId, int $viviendaId): bool
    {
        return $this->vigentes()->where('residente_id', $residenteId)->where('vivienda_id', $viviendaId)->exists();
    }

    public function tieneViviendas(int $residenteId): bool
    {
        return $this->vigentes()->where('residente_id', $residenteId)->exists();
    }

    /**
     * Ids de todos los residentes que vivieron en la vivienda
     *
     * @return list<int>
     */
    public function residentesDe(int $viviendaId): array
    {
        return array_values(array_map('intval', ViviendaResidente::query()
            ->where('vivienda_id', $viviendaId)
            ->distinct()
            ->pluck('residente_id')
            ->all()));
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): ViviendaResidente
    {
        return ViviendaResidente::query()->create($datos);
    }

    /**
     * Deja la vivienda sin responsable de pago
     */
    public function quitarResponsables(int $viviendaId): void
    {
        $this->vigentes()->where('vivienda_id', $viviendaId)->update(['es_responsable_pago' => false]);
    }

    public function tieneResponsable(int $viviendaId): bool
    {
        return $this->vigentes()->where('vivienda_id', $viviendaId)->where('es_responsable_pago', true)->exists();
    }

    /**
     * Vínculo vigente más antiguo de la vivienda
     */
    public function primeroDe(int $viviendaId): ?ViviendaResidente
    {
        return $this->vigentes()->where('vivienda_id', $viviendaId)->orderBy('id')->first();
    }

    /**
     * @return Builder<ViviendaResidente>
     */
    private function vigentes(): Builder
    {
        return ViviendaResidente::query()->whereNull('fecha_fin');
    }
}
