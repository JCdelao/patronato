<?php

namespace Patronato\Persistencia\Repositorios;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Patronato\Persistencia\Models\Incidencia;

class IncidenciaRepositorio extends Repositorio
{
    /**
     * Incidencias del residencial, de la más reciente a la más antigua
     *
     * @return LengthAwarePaginator<int, Incidencia>
     */
    public function paginar(?int $tipoId, bool $soloUrgentes, int $porPagina): LengthAwarePaginator
    {
        return Incidencia::query()
            ->when($tipoId, fn (Builder $query, int $id) => $query->where('tipo_incidencia_id', $id))
            ->when($soloUrgentes, fn (Builder $query) => $query->where('urgente', true))
            ->with(['tipo:id,nombre', 'guardia:id,nombre'])
            ->latest('ocurrida_en')
            ->latest('id')
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * Incidencias que registró el guardia desde la fecha, de la más reciente a la más antigua
     *
     * @return Collection<int, Incidencia>
     */
    public function deGuardia(int $guardiaId, CarbonInterface $desde): Collection
    {
        return Incidencia::query()
            ->where('guardia_id', $guardiaId)
            ->where('ocurrida_en', '>=', $desde)
            ->with(['tipo:id,nombre', 'guardia:id,nombre'])
            ->latest('ocurrida_en')
            ->latest('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Incidencia
    {
        return Incidencia::query()->create($datos);
    }
}
