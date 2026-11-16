<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Pagination\LengthAwarePaginator;
use Patronato\Persistencia\Models\Egreso;

class EgresoRepositorio extends Repositorio
{
    /**
     * Egresos del residencial, del más reciente al más antiguo, incluidos los anulados
     *
     * Busca por concepto, proveedor o número de comprobante, sin importar mayúsculas ni tildes.
     *
     * @return LengthAwarePaginator<int, Egreso>
     */
    public function paginar(string $buscar, int $porPagina): LengthAwarePaginator
    {
        return Egreso::query()
            ->when($buscar !== '', function ($query) use ($buscar) {
                $patron = Busqueda::patron($buscar);

                $query->where(function ($query) use ($patron) {
                    $query->whereRaw(Busqueda::columna('concepto').' like ?', [$patron])
                        ->orWhereRaw(Busqueda::columna('proveedor').' like ?', [$patron])
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
    public function crear(array $datos): Egreso
    {
        return Egreso::query()->create($datos);
    }

    /**
     * Vuelve a leer el egreso reservándolo hasta terminar la transacción
     */
    public function bloquearRegistro(int $egresoId): ?Egreso
    {
        return Egreso::query()->lockForUpdate()->find($egresoId);
    }

    /**
     * Carga lo que muestra el detalle: categoría y quien lo registró
     */
    public function conDetalle(Egreso $egreso): Egreso
    {
        return $egreso->load(['categoria', 'registrador']);
    }
}
