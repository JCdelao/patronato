<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\Ajuste;

class AjusteRepositorio
{
    /**
     * Ajustes de una vivienda, del más antiguo al más reciente, con quien los autorizó
     *
     * @return Collection<int, Ajuste>
     */
    public function deVivienda(int $viviendaId): Collection
    {
        return Ajuste::query()
            ->with('autorizador:id,name')
            ->where('vivienda_id', $viviendaId)
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Ajuste
    {
        return Ajuste::query()->create($datos);
    }
}
