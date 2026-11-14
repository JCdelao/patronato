<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\TipoVivienda;

class TipoViviendaRepositorio extends Repositorio
{
    /**
     * Tipos del residencial: activos primero y luego por nombre
     *
     * Cada tipo indica en tiene_viviendas si alguna vivienda lo usa.
     *
     * @return Collection<int, TipoVivienda>
     */
    public function catalogo(): Collection
    {
        return TipoVivienda::query()
            ->withExists('viviendas as tiene_viviendas')
            ->orderByDesc('activo')
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Id y nombre de los tipos activos
     *
     * @return Collection<int, TipoVivienda>
     */
    public function activos(): Collection
    {
        return TipoVivienda::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
    }

    /**
     * Indica si otro tipo del residencial usa el nombre, sin importar mayúsculas
     */
    public function existeNombre(int $residencialId, string $nombre, ?int $exceptoId): bool
    {
        return TipoVivienda::query()
            ->where('residencial_id', $residencialId)
            ->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])
            ->when($exceptoId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->exists();
    }

    public function tieneViviendas(TipoVivienda $tipo): bool
    {
        return $tipo->viviendas()->exists();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): TipoVivienda
    {
        return TipoVivienda::query()->create($datos);
    }
}
