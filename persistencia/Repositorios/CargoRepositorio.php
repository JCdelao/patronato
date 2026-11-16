<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
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

    /**
     * Crea el cargo si la vivienda todavía no tiene ese concepto en ese período
     *
     * La restricción única de la tabla impide duplicados aunque dos procesos corran a la vez.
     *
     * @param  array{residencial_id: int, vivienda_id: int, concepto_id: int, periodo: string, monto: string}  $datos
     */
    public function crearSiNoExiste(array $datos): bool
    {
        return Cargo::query()->insertOrIgnore([
            ...$datos,
            'origen' => 'sistema',
            'created_at' => now(),
            'updated_at' => now(),
        ]) > 0;
    }

    /**
     * Cargos de una vivienda con lo que ya cubren los pagos confirmados, del período más antiguo al más reciente
     *
     * Cada cargo trae en "aplicado" la suma de lo pagado.
     *
     * @return Collection<int, Cargo>
     */
    public function conAplicadoDe(int $viviendaId): Collection
    {
        return $this->conAplicadoDeViviendas([$viviendaId]);
    }

    /**
     * Cargos de varias viviendas con lo que ya cubren los pagos confirmados
     *
     * @param  array<int, int>  $viviendaIds
     * @return Collection<int, Cargo>
     */
    public function conAplicadoDeViviendas(array $viviendaIds): Collection
    {
        return Cargo::query()
            ->with('concepto:id,nombre')
            ->whereIn('vivienda_id', $viviendaIds)
            ->select('cargos.*')
            ->selectSub(fn (Builder $query) => $query
                ->from('pago_aplicaciones')
                ->join('pagos', 'pagos.id', '=', 'pago_aplicaciones.pago_id')
                ->whereColumn('pago_aplicaciones.cargo_id', 'cargos.id')
                ->where('pagos.estado', 'confirmado')
                ->selectRaw('coalesce(sum(pago_aplicaciones.monto), 0)'), 'aplicado')
            ->orderBy('periodo')
            ->orderBy('concepto_id')
            ->get();
    }
}
