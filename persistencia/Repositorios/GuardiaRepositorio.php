<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Patronato\Persistencia\Models\Guardia;

class GuardiaRepositorio extends Repositorio
{
    /**
     * Guardias activos o inactivos del residencial, por nombre
     *
     * Busca por nombre, identidad o teléfono, sin importar mayúsculas ni tildes.
     *
     * @return LengthAwarePaginator<int, Guardia>
     */
    public function paginar(bool $activos, string $buscar, int $porPagina): LengthAwarePaginator
    {
        return Guardia::query()
            ->where('activo', $activos)
            ->when($buscar !== '', function (Builder $query) use ($buscar) {
                $patron = Busqueda::patron($buscar);

                $query->where(function (Builder $query) use ($patron) {
                    $query->whereRaw(Busqueda::columna('nombre').' like ?', [$patron])
                        ->orWhereRaw(Busqueda::columna('identidad').' like ?', [$patron])
                        ->orWhereRaw(Busqueda::columna('telefono').' like ?', [$patron]);
                });
            })
            ->with('cuenta:id,email,activo')
            ->orderBy('nombre')
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Guardia
    {
        return Guardia::query()->create($datos);
    }

    /**
     * Vuelve a leer el guardia reservándolo hasta terminar la transacción
     */
    public function bloquearRegistro(int $guardiaId): ?Guardia
    {
        return Guardia::query()->lockForUpdate()->find($guardiaId);
    }

    /**
     * Guardias activos del residencial, por nombre
     *
     * @return Collection<int, Guardia>
     */
    public function activos(): Collection
    {
        return Guardia::query()->where('activo', true)->orderBy('nombre')->get();
    }

    /**
     * Guardia activo vinculado a la cuenta, si tiene uno
     */
    public function activoDeUsuario(int $usuarioId): ?Guardia
    {
        return Guardia::query()->where('user_id', $usuarioId)->where('activo', true)->first();
    }

    /**
     * Quita la cuenta al guardia que la tenga y se la asigna a otro
     */
    public function asignarCuenta(int $usuarioId, ?int $guardiaId): void
    {
        Guardia::query()->where('user_id', $usuarioId)->update(['user_id' => null]);

        if ($guardiaId !== null) {
            Guardia::query()->whereKey($guardiaId)->update(['user_id' => $usuarioId]);
        }
    }
}
