<?php

namespace Patronato\Persistencia\Repositorios;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\Turno;

class TurnoRepositorio extends Repositorio
{
    /**
     * Turnos que ocupan alguna parte del rango, con su guardia, por hora de inicio
     *
     * @return Collection<int, Turno>
     */
    public function entre(CarbonInterface $desde, CarbonInterface $hasta, ?int $guardiaId = null): Collection
    {
        return Turno::query()
            ->where('inicio', '<', $hasta)
            ->where('fin', '>', $desde)
            ->when($guardiaId, fn (Builder $query, int $id) => $query->where('guardia_id', $id))
            ->with(['guardia:id,nombre', 'entrega.guardiaRecibe:id,nombre'])
            ->orderBy('inicio')
            ->orderBy('id')
            ->get();
    }

    /**
     * Turnos del guardia que se cruzan con alguno de los rangos
     *
     * @param  list<array{inicio: CarbonInterface, fin: CarbonInterface}>  $rangos
     * @return Collection<int, Turno>
     */
    public function superpuestos(int $guardiaId, array $rangos): Collection
    {
        return Turno::query()
            ->where('guardia_id', $guardiaId)
            ->where(function (Builder $query) use ($rangos) {
                foreach ($rangos as $rango) {
                    $query->orWhere(fn (Builder $query) => $query->where('inicio', '<', $rango['fin'])->where('fin', '>', $rango['inicio']));
                }
            })
            ->orderBy('inicio')
            ->get();
    }

    /**
     * Turno que el guardia inició y todavía no entrega
     */
    public function enCurso(int $guardiaId): ?Turno
    {
        return Turno::query()
            ->where('guardia_id', $guardiaId)
            ->whereNotNull('inicio_real')
            ->whereDoesntHave('entrega')
            ->latest('inicio')
            ->first();
    }

    /**
     * Turno del guardia que se puede iniciar: sin iniciar, sin terminar y que empieza antes del límite
     */
    public function porIniciar(int $guardiaId, CarbonInterface $ahora, CarbonInterface $limite): ?Turno
    {
        return Turno::query()
            ->where('guardia_id', $guardiaId)
            ->whereNull('inicio_real')
            ->where('inicio', '<=', $limite)
            ->where('fin', '>', $ahora)
            ->orderBy('inicio')
            ->first();
    }

    /**
     * Turnos de otros guardias que empiezan en el rango, por hora de inicio
     *
     * @return Collection<int, Turno>
     */
    public function queEmpiezanEntre(CarbonInterface $desde, CarbonInterface $hasta, int $exceptoGuardiaId): Collection
    {
        return Turno::query()
            ->whereBetween('inicio', [$desde, $hasta])
            ->where('guardia_id', '!=', $exceptoGuardiaId)
            ->with('guardia:id,activo')
            ->orderBy('inicio')
            ->get();
    }

    /**
     * Vuelve a leer el turno reservándolo hasta terminar la transacción
     */
    public function bloquearRegistro(int $turnoId): ?Turno
    {
        return Turno::query()->lockForUpdate()->find($turnoId);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Turno
    {
        return Turno::query()->create($datos);
    }
}
