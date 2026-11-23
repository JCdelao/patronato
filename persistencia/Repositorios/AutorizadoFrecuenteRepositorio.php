<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Patronato\Persistencia\Models\AutorizadoFrecuente;

class AutorizadoFrecuenteRepositorio extends Repositorio
{
    /**
     * @param  list<int>  $dias
     * @param  list<int>  $viviendas
     */
    public function existeActivoIdentico(int $residencial, string $nombre, string $tipo, array $dias, string $inicio, string $fin, array $viviendas): bool
    {
        return AutorizadoFrecuente::query()->where('residencial_id', $residencial)->where('activo', true)
            ->where('hora_inicio', $inicio)->where('hora_fin', $fin)->with('viviendas:id')
            ->get()->contains(function (AutorizadoFrecuente $actual) use ($nombre, $tipo, $dias, $viviendas): bool {
                $diasActuales = $actual->dias_semana;
                sort($diasActuales);
                $destinos = $actual->viviendas->modelKeys();
                sort($destinos);

                return $diasActuales === $dias && $destinos === $viviendas
                    && Str::lower(Str::squish($actual->nombre)) === Str::lower($nombre)
                    && Str::lower(Str::squish($actual->tipo)) === Str::lower($tipo);
            });
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  list<int>  $viviendas
     */
    public function crearConViviendas(int $residencial, array $datos, array $viviendas): AutorizadoFrecuente
    {
        $autorizado = AutorizadoFrecuente::query()->create(['residencial_id' => $residencial, ...$datos]);
        $autorizado->viviendas()->attach($viviendas, ['residencial_id' => $residencial]);

        return $autorizado;
    }

    /** @return Collection<int, AutorizadoFrecuente> */
    public function deVivienda(int $residencial, int $vivienda): Collection
    {
        return AutorizadoFrecuente::query()->where('residencial_id', $residencial)
            ->whereHas('viviendas', fn (Builder $consulta) => $consulta->whereKey($vivienda)
                ->where('autorizado_frecuente_viviendas.residencial_id', $residencial))
            ->orderBy('nombre')->orderBy('id')->get();
    }
}
