<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Patronato\Persistencia\Models\Residente;

class ResidenteRepositorio extends Repositorio
{
    /**
     * Residentes activos con sus viviendas y sus carnets, por nombre
     *
     * Busca por nombre, identidad, número de carnet o código de vivienda.
     *
     * @return LengthAwarePaginator<int, Residente>
     */
    public function paginarActivosConCarnets(string $buscar, int $porPagina): LengthAwarePaginator
    {
        return Residente::query()
            ->where('activo', true)
            ->when($buscar !== '', function (Builder $query) use ($buscar) {
                $patron = Busqueda::patron($buscar);

                $query->where(function (Builder $query) use ($patron) {
                    $query->whereRaw(Busqueda::columna("nombres || ' ' || apellidos").' like ?', [$patron])
                        ->orWhereRaw(Busqueda::columna('identidad').' like ?', [$patron])
                        ->orWhereHas('carnets', fn (Builder $carnet) => $carnet->whereRaw(Busqueda::columna('numero').' like ?', [$patron]))
                        ->orWhereHas('vinculosVigentes.vivienda', fn (Builder $vivienda) => $vivienda->whereRaw(Busqueda::columna('codigo').' like ?', [$patron]));
                });
            })
            ->with(['carnets', 'vinculosVigentes.vivienda:id,codigo'])
            ->orderBy('nombres')
            ->orderBy('apellidos')
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * Residentes inactivos con las viviendas donde vivieron
     *
     * Busca por nombre, identidad o teléfono.
     *
     * @return LengthAwarePaginator<int, Residente>
     */
    public function paginarInactivos(string $buscar, int $porPagina): LengthAwarePaginator
    {
        return Residente::query()
            ->where('activo', false)
            ->when($buscar !== '', function (Builder $query) use ($buscar) {
                $patron = Busqueda::patron($buscar);

                $query->where(function (Builder $query) use ($patron) {
                    $query->whereRaw(Busqueda::columna("nombres || ' ' || apellidos").' like ?', [$patron])
                        ->orWhereRaw(Busqueda::columna('identidad').' like ?', [$patron])
                        ->orWhereRaw(Busqueda::columna('telefono').' like ?', [$patron]);
                });
            })
            ->with(['vinculos' => fn ($query) => $query->with('vivienda')->orderByDesc('fecha_inicio')->orderByDesc('id')])
            ->orderBy('nombres')
            ->orderBy('apellidos')
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * Residentes activos con sus viviendas, por nombre
     *
     * @return Collection<int, Residente>
     */
    public function activosConViviendas(): Collection
    {
        return Residente::query()
            ->where('activo', true)
            ->with('vinculosVigentes.vivienda')
            ->orderBy('nombres')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Residente
    {
        return Residente::query()->create($datos);
    }

    /**
     * Residente activo vinculado a la cuenta, si tiene uno; sin él no se usa el portal
     */
    public function activoDeUsuario(int $usuarioId): ?Residente
    {
        return Residente::query()->where('user_id', $usuarioId)->where('activo', true)->first();
    }

    public function bloquearActivoDeUsuario(int $usuarioId, int $residencialId): ?Residente
    {
        return Residente::query()
            ->where('residencial_id', $residencialId)
            ->where('user_id', $usuarioId)
            ->where('activo', true)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Quita la cuenta al residente que la tenga y se la asigna a otro
     */
    public function asignarCuenta(int $usuarioId, ?int $residenteId): void
    {
        Residente::query()->where('user_id', $usuarioId)->update(['user_id' => null]);

        if ($residenteId !== null) {
            Residente::query()->whereKey($residenteId)->update(['user_id' => $usuarioId]);
        }
    }
}
