<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Pagination\LengthAwarePaginator;
use Patronato\Persistencia\Models\Ingreso;

class IngresoRepositorio extends Repositorio
{
    /**
     * Ingresos del residencial, del más reciente al más antiguo, incluidos los anulados
     *
     * Busca por concepto o número de comprobante, sin importar mayúsculas ni tildes.
     *
     * @return LengthAwarePaginator<int, Ingreso>
     */
    public function paginar(string $buscar, int $porPagina): LengthAwarePaginator
    {
        return Ingreso::query()
            ->when($buscar !== '', function ($query) use ($buscar) {
                $patron = Busqueda::patron($buscar);

                $query->where(function ($query) use ($patron) {
                    $query->whereRaw(Busqueda::columna('concepto').' like ?', [$patron])
                        ->orWhereRaw(Busqueda::columna('numero_comprobante').' like ?', [$patron]);
                });
            })
            ->with('categoria')
            ->latest('fecha')
            ->latest('id')
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Ingreso
    {
        return Ingreso::query()->create($datos);
    }

    /**
     * Vuelve a leer el ingreso reservándolo hasta terminar la transacción
     */
    public function bloquearRegistro(int $ingresoId): ?Ingreso
    {
        return Ingreso::query()->lockForUpdate()->find($ingresoId);
    }

    /**
     * Ingreso que generó un cobro, reservado hasta terminar la transacción
     */
    public function bloquearDePago(int $pagoId): ?Ingreso
    {
        return Ingreso::query()->lockForUpdate()->where('pago_id', $pagoId)->first();
    }

    /**
     * Carga lo que muestra el detalle: categoría, cobro y quien lo registró
     */
    public function conDetalle(Ingreso $ingreso): Ingreso
    {
        return $ingreso->load(['categoria', 'pago', 'registrador']);
    }
}
