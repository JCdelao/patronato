<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\Residencial;

class ResidencialRepositorio extends Repositorio
{
    /**
     * @return Collection<int, Residencial>
     */
    public function todos(): Collection
    {
        return Residencial::query()->orderBy('nombre')->get();
    }

    /**
     * Residenciales que no están suspendidos
     *
     * @return Collection<int, Residencial>
     */
    public function activos(): Collection
    {
        return Residencial::query()->where('estado', Residencial::ACTIVO)->orderBy('id')->get();
    }

    public function buscar(int $id): Residencial
    {
        return Residencial::query()->findOrFail($id);
    }

    public function bloquear(int $id): Residencial
    {
        return Residencial::query()->lockForUpdate()->findOrFail($id);
    }

    /**
     * Indica si ya existe un residencial con el nombre, sin importar mayúsculas
     */
    public function existeNombre(string $nombre): bool
    {
        return Residencial::query()->whereRaw('lower(nombre) = lower(?)', [$nombre])->exists();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Residencial
    {
        return Residencial::query()->create($datos);
    }
}
