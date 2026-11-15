<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Patronato\Persistencia\Models\Vivienda;

class ViviendaRepositorio extends Repositorio
{
    /**
     * Viviendas activas o inactivas del padrón, con sus residentes
     *
     * Busca por código, nombre o teléfono de un residente.
     *
     * @return LengthAwarePaginator<int, Vivienda>
     */
    public function paginarPadron(bool $activas, string $buscar, int $porPagina): LengthAwarePaginator
    {
        return Vivienda::query()
            ->where('activa', $activas)
            ->when($buscar !== '', function (Builder $query) use ($buscar) {
                $patron = Busqueda::patron($buscar);

                $query->where(function (Builder $query) use ($patron) {
                    $query->whereRaw(Busqueda::columna('codigo').' like ?', [$patron])
                        ->orWhereHas('vinculosVigentes.residente', function (Builder $query) use ($patron) {
                            $query->whereRaw(Busqueda::columna("nombres || ' ' || apellidos").' like ?', [$patron])
                                ->orWhereRaw(Busqueda::columna('telefono').' like ?', [$patron]);
                        });
                });
            })
            ->with(['tipoVivienda:id,nombre', 'padre:id,codigo', 'vinculosVigentes.residente.vinculosVigentes.vivienda'])
            ->orderBy('codigo')
            ->paginate($porPagina)
            ->withQueryString();
    }

    public function buscar(int $id): ?Vivienda
    {
        return Vivienda::query()->find($id);
    }

    /**
     * Vuelve a leer el tipo y la vivienda a la que pertenece
     */
    public function cargarTipoYPadre(Vivienda $vivienda): void
    {
        $vivienda->load(['tipoVivienda:id,nombre', 'padre:id,codigo']);
    }

    public function tieneHijas(Vivienda $vivienda): bool
    {
        return $vivienda->hijas()->exists();
    }

    /**
     * Id, código y vivienda a la que pertenecen las viviendas activas
     *
     * @return Collection<int, Vivienda>
     */
    public function activas(): Collection
    {
        return Vivienda::query()
            ->where('activa', true)
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'vivienda_padre_id']);
    }

    /**
     * Viviendas activas con su responsable de pago
     *
     * Con un id devuelve solo esa vivienda; si no, busca por código o nombre del residente.
     *
     * @return Collection<int, Vivienda>
     */
    public function activasConResponsable(string $buscar, ?int $viviendaId, int $limite): Collection
    {
        return Vivienda::query()
            ->where('activa', true)
            ->when($viviendaId !== null, fn (Builder $query) => $query->whereKey($viviendaId))
            ->when($viviendaId === null && $buscar !== '', function (Builder $query) use ($buscar) {
                $patron = Busqueda::patron($buscar);

                $query->where(function (Builder $query) use ($patron) {
                    $query->whereRaw(Busqueda::columna('codigo').' like ?', [$patron])
                        ->orWhereHas('vinculosVigentes.residente', function (Builder $query) use ($patron) {
                            $query->whereRaw(Busqueda::columna("nombres || ' ' || apellidos").' like ?', [$patron]);
                        });
                });
            })
            ->with(['vinculosVigentes' => fn ($query) => $query->where('es_responsable_pago', true)->with('residente')])
            ->orderBy('codigo')
            ->limit($limite)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Vivienda
    {
        return Vivienda::query()->create($datos);
    }

    public function tieneResidentes(Vivienda $vivienda): bool
    {
        return $vivienda->vinculosVigentes()->exists();
    }

    /**
     * Deja inactiva una vivienda que no se pudo borrar
     */
    public function inactivar(Vivienda $vivienda): void
    {
        // Tras un borrado fallido el modelo cree que ya no existe
        $vivienda->exists = true;

        $vivienda->update(['activa' => false]);
    }
}
