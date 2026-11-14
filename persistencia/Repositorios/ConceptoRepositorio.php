<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
