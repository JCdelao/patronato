<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\CategoriaFinanciera;

class CategoriaFinancieraRepositorio extends Repositorio
{
    /**
     * Categorías activas de un tipo, por nombre
     *
     * @return Collection<int, CategoriaFinanciera>
     */
    public function activasDe(string $tipo): Collection
    {
        return CategoriaFinanciera::query()
            ->where('tipo', $tipo)
            ->where('activa', true)
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Indica si otra categoría del mismo tipo usa el nombre, sin importar mayúsculas
     */
    public function existeNombre(string $tipo, string $nombre, ?int $exceptoId = null): bool
    {
        return CategoriaFinanciera::query()
            ->where('tipo', $tipo)
            ->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])
            ->when($exceptoId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->exists();
    }

    /**
     * Categoría de un residencial por su nombre exacto; la crea activa si no existe
     */
    public function obtenerOCrear(int $residencialId, string $tipo, string $nombre): CategoriaFinanciera
    {
        return CategoriaFinanciera::query()->firstOrCreate(
            ['residencial_id' => $residencialId, 'tipo' => $tipo, 'nombre' => $nombre],
            ['activa' => true],
        );
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): CategoriaFinanciera
    {
        return CategoriaFinanciera::query()->create($datos);
    }
}
