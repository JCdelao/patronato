<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\Rol;

class RolRepositorio
{
    public function porCodigo(string $codigo): Rol
    {
        return Rol::query()->where('codigo', $codigo)->firstOrFail();
    }

    public function codigoDe(int $id): ?string
    {
        $codigo = Rol::query()->whereKey($id)->value('codigo');

        return is_string($codigo) ? $codigo : null;
    }

    /**
     * Id de cada rol, por código
     *
     * @param  array<int, string>  $codigos
     * @return array<string, int>
     */
    public function idsPorCodigo(array $codigos): array
    {
        return Rol::query()
            ->whereIn('codigo', $codigos)
            ->pluck('id', 'codigo')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Roles que no están en la lista, en el orden en que se crearon
     *
     * @param  array<int, string>  $excluidos
     * @return Collection<int, Rol>
     */
    public function excepto(array $excluidos): Collection
    {
        return Rol::query()->whereNotIn('codigo', $excluidos)->orderBy('id')->get();
    }
}
