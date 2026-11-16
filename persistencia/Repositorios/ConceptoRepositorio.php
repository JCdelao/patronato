<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Patronato\Persistencia\Models\ConceptoCobro;

class ConceptoRepositorio extends Repositorio
{
    /**
     * Conceptos del residencial: activos primero, la cuota mensual al inicio y luego por nombre
     *
     * Cada concepto indica en tiene_cargos si ya generó cargos.
     *
     * @return Collection<int, ConceptoCobro>
     */
    public function catalogo(): Collection
    {
        return ConceptoCobro::query()
            ->withExists('cargos as tiene_cargos')
            ->withCount(['viviendas as viviendas_elegidas' => fn (Builder $query) => $query->where('activa', true)])
            ->orderByDesc('activo')
            ->orderByDesc('es_cuota_unificada')
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Concepto de la cuota mensual de las viviendas; lo crea si no existe
     */
    public function cuotaMensual(int $residencialId, string $nombre): ConceptoCobro
    {
        return ConceptoCobro::query()->firstOrCreate(
            ['residencial_id' => $residencialId, 'es_cuota_unificada' => true],
            ['nombre' => $nombre, 'tipo' => ConceptoCobro::RECURRENTE],
        );
    }

    /**
     * Conceptos activos que se cobran aparte de la cuota mensual
     *
     * @return Collection<int, ConceptoCobro>
     */
    public function cobrablesDe(int $residencialId): Collection
    {
        return ConceptoCobro::query()
            ->where('residencial_id', $residencialId)
            ->where('activo', true)
            ->where('es_cuota_unificada', false)
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Conceptos activos que se cobran solo a las viviendas elegidas
     *
     * @return Collection<int, ConceptoCobro>
     */
    public function paraAlgunas(): Collection
    {
        return ConceptoCobro::query()
            ->where('activo', true)
            ->where('es_cuota_unificada', false)
            ->where('alcance', ConceptoCobro::ALGUNAS)
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Viviendas elegidas de cada concepto
     *
     * @param  list<int>  $conceptoIds
     * @return array<int, array<int, true>> conceptos con sus ids de vivienda como llaves
     */
    public function viviendasElegidas(array $conceptoIds): array
    {
        $elegidas = [];

        $filas = DB::table('concepto_viviendas')->whereIn('concepto_id', $conceptoIds)->get(['concepto_id', 'vivienda_id']);

        foreach ($filas as $fila) {
            $elegidas[(int) $fila->concepto_id][(int) $fila->vivienda_id] = true;
        }

        return $elegidas;
    }

    public function cuotaMensualExistente(int $residencialId): ?ConceptoCobro
    {
        return ConceptoCobro::query()->where('residencial_id', $residencialId)->where('es_cuota_unificada', true)->first();
    }

    /**
     * Indica si otro concepto usa el nombre, sin importar mayúsculas
     */
    public function existeNombre(string $nombre, ?int $exceptoId): bool
    {
        return ConceptoCobro::query()
            ->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])
            ->when($exceptoId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): ConceptoCobro
    {
        return ConceptoCobro::query()->create($datos);
    }
}
