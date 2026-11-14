<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\Tarifa;

/**
 * Montos de cada concepto con sus vigencias
 *
 * El alcance de un monto es el concepto y, si se indica, un tipo de vivienda.
 * Sin tipo, el monto aplica a todas las viviendas.
 */
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
        return Tarifa::query()
            ->whereIn('concepto_id', $conceptoIds)
            ->whereNull('tipo_vivienda_id')
            ->whereNull('vivienda_id')
            ->orderBy('vigente_desde')
            ->get();
    }

    /**
     * Montos de un concepto para varios tipos de vivienda, del más antiguo al más reciente
     *
     * @param  array<int, int>  $tipoIds
     * @return Collection<int, Tarifa>
     */
    public function deTipos(int $conceptoId, array $tipoIds): Collection
    {
        return Tarifa::query()
            ->where('concepto_id', $conceptoId)
            ->whereIn('tipo_vivienda_id', $tipoIds)
            ->orderBy('vigente_desde')
            ->get();
    }

    /**
     * Monto más reciente del alcance, aunque todavía no rija
     */
    public function ultima(int $conceptoId, ?int $tipoViviendaId, ?int $viviendaId = null): ?Tarifa
    {
        return $this->alcance($conceptoId, $tipoViviendaId, $viviendaId)->orderByDesc('vigente_desde')->first();
    }

    /**
     * Monto del alcance que empieza exactamente en la fecha
     */
    public function desde(int $conceptoId, ?int $tipoViviendaId, string $fecha, ?int $viviendaId = null): ?Tarifa
    {
        return $this->alcance($conceptoId, $tipoViviendaId, $viviendaId)->whereDate('vigente_desde', $fecha)->first();
    }

    /**
     * Borra los montos del alcance que empiezan después de la fecha
     */
    public function borrarPosteriores(int $conceptoId, ?int $tipoViviendaId, string $fecha, ?int $viviendaId = null): void
    {
        $this->alcance($conceptoId, $tipoViviendaId, $viviendaId)->whereDate('vigente_desde', '>', $fecha)->delete();
    }

    /**
     * Termina los montos del alcance anteriores a la fecha que seguían abiertos
     */
    public function cerrarAnteriores(int $conceptoId, ?int $tipoViviendaId, string $fecha, string $hasta, ?int $viviendaId = null): void
    {
        $this->alcance($conceptoId, $tipoViviendaId, $viviendaId)
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

    public function eliminarDeTipo(int $tipoViviendaId): void
    {
        Tarifa::query()->where('tipo_vivienda_id', $tipoViviendaId)->delete();
    }

    /**
     * Montos especiales de un concepto, con el código de la vivienda, del más antiguo al más reciente
     *
     * @return Collection<int, Tarifa>
     */
    public function especiales(int $conceptoId): Collection
    {
        return Tarifa::query()
            ->with('vivienda:id,codigo')
            ->where('concepto_id', $conceptoId)
            ->whereNotNull('vivienda_id')
            ->orderBy('vigente_desde')
            ->get();
    }

    /**
     * Monto del alcance que rige en la fecha
     */
    public function vigenteEn(int $conceptoId, ?int $tipoViviendaId, string $fecha, ?int $viviendaId = null): ?Tarifa
    {
        return $this->alcance($conceptoId, $tipoViviendaId, $viviendaId)
            ->whereDate('vigente_desde', '<=', $fecha)
            ->where(fn (Builder $query) => $query->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $fecha))
            ->orderByDesc('vigente_desde')
            ->first();
    }

    /**
     * Tarifas de un concepto que alcanzan a una vivienda, a su tipo o a todas
     *
     * @return Builder<Tarifa>
     */
    private function alcance(int $conceptoId, ?int $tipoViviendaId, ?int $viviendaId = null): Builder
    {
        $query = Tarifa::query()->where('concepto_id', $conceptoId);

        if ($viviendaId !== null) {
            return $query->where('vivienda_id', $viviendaId);
        }

        return $query
            ->whereNull('vivienda_id')
            ->when(
                $tipoViviendaId === null,
                fn (Builder $query) => $query->whereNull('tipo_vivienda_id'),
                fn (Builder $query) => $query->where('tipo_vivienda_id', $tipoViviendaId),
            );
    }
}
