<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\CategoriaFinanciera;
use Patronato\Persistencia\Models\Egreso;
use Patronato\Persistencia\Models\Ingreso;
use Patronato\Persistencia\Models\Presupuesto;

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
     * Todas las categorías de un tipo, activas e inactivas, por nombre
     *
     * @return Collection<int, CategoriaFinanciera>
     */
    public function todasDe(string $tipo): Collection
    {
        return CategoriaFinanciera::query()
            ->where('tipo', $tipo)
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Categorías inactivas de un tipo, por nombre
     *
     * @return Collection<int, CategoriaFinanciera>
     */
    public function inactivasDe(string $tipo): Collection
    {
        return CategoriaFinanciera::query()
            ->where('tipo', $tipo)
            ->where('activa', false)
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Ids de las categorías que ya tienen egresos, ingresos o presupuestos, de cualquier año
     *
     * Una categoría en uso no se puede borrar sin romper el historial.
     *
     * @return list<int>
     */
    public function idsEnUso(): array
    {
        $ids = Egreso::query()->distinct()->pluck('categoria_id')
            ->merge(Ingreso::query()->distinct()->pluck('categoria_id'))
            ->merge(Presupuesto::query()->distinct()->pluck('categoria_id'));

        return array_values(array_unique(array_map('intval', $ids->all())));
    }

    /**
     * Indica si la categoría ya tiene egresos, ingresos o presupuestos
     */
    public function estaEnUso(int $categoriaId): bool
    {
        return Egreso::query()->where('categoria_id', $categoriaId)->exists()
            || Ingreso::query()->where('categoria_id', $categoriaId)->exists()
            || Presupuesto::query()->where('categoria_id', $categoriaId)->exists();
    }

    /**
     * Vuelve a leer la categoría reservándola hasta terminar la transacción
     */
    public function bloquearRegistro(int $categoriaId): ?CategoriaFinanciera
    {
        return CategoriaFinanciera::query()->lockForUpdate()->find($categoriaId);
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
