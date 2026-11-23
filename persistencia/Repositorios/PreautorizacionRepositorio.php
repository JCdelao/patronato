<?php

namespace Patronato\Persistencia\Repositorios;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Patronato\Persistencia\Models\Preautorizacion;

class PreautorizacionRepositorio extends Repositorio
{
    /** @param array<string, mixed> $datos */
    public function crearSiNueva(array $datos): ?Preautorizacion
    {
        if (Preautorizacion::query()->where($datos)->exists()) {
            return null;
        }

        return Preautorizacion::query()->create($datos);
    }

    /** @return Collection<int, Preautorizacion> */
    public function delDia(int $residencialId, int $viviendaId, CarbonImmutable $inicio, CarbonImmutable $fin): Collection
    {
        $vinculoVigente = DB::table('vivienda_residentes as vinculo')
            ->join('residentes', 'residentes.id', '=', 'vinculo.residente_id')
            ->whereColumn('vinculo.residencial_id', 'preautorizaciones.residencial_id')
            ->whereColumn('vinculo.residente_id', 'preautorizaciones.residente_id')
            ->whereColumn('vinculo.vivienda_id', 'preautorizaciones.vivienda_id')
            ->whereNull('vinculo.fecha_fin')
            ->where('residentes.activo', true)
            ->selectRaw('1')->limit(1);

        return Preautorizacion::query()
            ->where('residencial_id', $residencialId)->where('vivienda_id', $viviendaId)
            ->where('inicio', '<', $fin)->where('fin', '>', $inicio)
            ->select('preautorizaciones.*')->selectSub($vinculoVigente, 'vinculo_vigente')
            ->with('medio:id,nombre')->orderBy('inicio')->orderBy('id')->get();
    }
}
