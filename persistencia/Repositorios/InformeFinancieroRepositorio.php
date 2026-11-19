<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Patronato\Persistencia\Models\Egreso;
use Patronato\Persistencia\Models\Ingreso;

/**
 * Sumas de los ingresos y egresos vigentes del residencial para el informe financiero
 *
 * Los anulados no suman. Las fechas son las del movimiento (Y-m-d), no las de registro.
 */
class InformeFinancieroRepositorio
{
    /**
     * Total vigente de ingresos y de egresos con fecha anterior a un día
     *
     * @return array{ingresos: string, egresos: string}
     */
    public function totalesAntesDe(string $fecha): array
    {
        return [
            'ingresos' => (string) ($this->vigentes(Ingreso::class)->where('fecha', '<', $fecha)->sum('monto') ?: '0'),
            'egresos' => (string) ($this->vigentes(Egreso::class)->where('fecha', '<', $fecha)->sum('monto') ?: '0'),
        ];
    }

    /**
     * Totales por categoría entre dos fechas, ambas incluidas
     *
     * @return array{ingresos: list<array{categoria: string, total: string}>, egresos: list<array{categoria: string, total: string}>}
     */
    public function totalesPorCategoria(string $desde, string $hasta): array
    {
        return [
            'ingresos' => $this->porCategoria(Ingreso::class, 'ingresos', $desde, $hasta),
            'egresos' => $this->porCategoria(Egreso::class, 'egresos', $desde, $hasta),
        ];
    }

    /**
     * Totales de cada día con movimientos entre dos fechas; el negocio los agrupa por mes
     *
     * @return array{ingresos: array<string, string>, egresos: array<string, string>}
     */
    public function totalesPorDia(string $desde, string $hasta): array
    {
        return [
            'ingresos' => $this->porDia(Ingreso::class, $desde, $hasta),
            'egresos' => $this->porDia(Egreso::class, $desde, $hasta),
        ];
    }

    /**
     * @param  class-string<Ingreso|Egreso>  $modelo
     * @return Builder<Ingreso>|Builder<Egreso>
     */
    private function vigentes(string $modelo): Builder
    {
        return $modelo::query()->where('estado', $modelo::VIGENTE);
    }

    /**
     * @param  class-string<Ingreso|Egreso>  $modelo
     * @param  'ingresos'|'egresos'  $tabla
     * @return list<array{categoria: string, total: string}>
     */
    private function porCategoria(string $modelo, string $tabla, string $desde, string $hasta): array
    {
        return array_values($this->vigentes($modelo)
            ->join('categorias_financieras', 'categorias_financieras.id', '=', "{$tabla}.categoria_id")
            ->whereBetween("{$tabla}.fecha", [$desde, $hasta])
            ->groupBy('categorias_financieras.nombre')
            ->orderBy('categorias_financieras.nombre')
            ->selectRaw("categorias_financieras.nombre as categoria, sum({$tabla}.monto) as total")
            ->toBase()
            ->get()
            ->map(fn (object $fila): array => ['categoria' => (string) $fila->categoria, 'total' => (string) $fila->total])
            ->all());
    }

    /**
     * @param  class-string<Ingreso|Egreso>  $modelo
     * @return array<string, string>
     */
    private function porDia(string $modelo, string $desde, string $hasta): array
    {
        return $this->vigentes($modelo)
            ->whereBetween('fecha', [$desde, $hasta])
            ->groupBy('fecha')
            ->selectRaw('fecha, sum(monto) as total')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $fila): array => [substr((string) $fila->fecha, 0, 10) => (string) $fila->total])
            ->all();
    }
}
