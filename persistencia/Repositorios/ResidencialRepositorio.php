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
