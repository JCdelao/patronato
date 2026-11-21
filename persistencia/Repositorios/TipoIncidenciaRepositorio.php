<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\TipoIncidencia;

class TipoIncidenciaRepositorio extends Repositorio
{
    /**
     * Tipos del residencial, activos e inactivos, por nombre
     *
     * @return Collection<int, TipoIncidencia>
     */
    public function todos(): Collection
    {
        return TipoIncidencia::query()->orderBy('nombre')->get();
    }

    /**
     * Tipos activos del residencial, por nombre
     *
     * @return Collection<int, TipoIncidencia>
     */
    public function activos(): Collection
    {
        return TipoIncidencia::query()->where('activo', true)->orderBy('nombre')->get();
    }

    /**
     * Indica si otro tipo del residencial usa el nombre, sin importar mayúsculas
     */
    public function existeNombre(string $nombre, ?int $exceptoId = null): bool
    {
        return TipoIncidencia::query()
            ->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])
            ->when($exceptoId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): TipoIncidencia
    {
        return TipoIncidencia::query()->create($datos);
    }
}
