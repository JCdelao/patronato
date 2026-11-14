<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\Tarifa;

class TarifaRepositorio extends Repositorio
{
    /**
     * Montos generales de varios conceptos, del más antiguo al más reciente
     *
     * @param  array<int, int>  $conceptoIds
     * @return Collection<int, Tarifa>
     */
    public function generalesDe(array $conceptoIds): Collection
    {
        return $this->generales()->whereIn('concepto_id', $conceptoIds)->orderBy('vigente_desde')->get();
    }

    /**
     * Monto general más reciente de un concepto, aunque todavía no rija
     */
    public function ultimaGeneral(int $conceptoId): ?Tarifa
    {
        return $this->generales()->where('concepto_id', $conceptoId)->orderByDesc('vigente_desde')->first();
    }

    /**
     * Monto general que empieza exactamente en la fecha
     */
    public function generalDesde(int $conceptoId, string $fecha): ?Tarifa
    {
        return $this->generales()->where('concepto_id', $conceptoId)->whereDate('vigente_desde', $fecha)->first();
    }

    /**
     * Borra los montos generales que empiezan después de la fecha
     */
    public function borrarGeneralesPosteriores(int $conceptoId, string $fecha): void
    {
        $this->generales()->where('concepto_id', $conceptoId)->whereDate('vigente_desde', '>', $fecha)->delete();
    }

    /**
     * Termina los montos generales anteriores a la fecha que seguían abiertos
     */
    public function cerrarGeneralesAnteriores(int $conceptoId, string $fecha, string $hasta): void
    {
        $this->generales()
            ->where('concepto_id', $conceptoId)
            ->whereDate('vigente_desde', '<', $fecha)
            ->where(fn (Builder $query) => $query->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $fecha))
            ->update(['vigente_hasta' => $hasta]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Tarifa
    {
        return Tarifa::query()->create($datos);
    }

    public function eliminarDeVivienda(int $viviendaId): void
    {
        Tarifa::query()->where('vivienda_id', $viviendaId)->delete();
    }

    public function eliminarDeConcepto(int $conceptoId): void
    {
        Tarifa::query()->where('concepto_id', $conceptoId)->delete();
    }

    /**
     * Tarifas que aplican a todas las viviendas
     *
     * @return Builder<Tarifa>
     */
    private function generales(): Builder
    {
        return Tarifa::query()->whereNull('tipo_vivienda')->whereNull('vivienda_id');
    }
}
