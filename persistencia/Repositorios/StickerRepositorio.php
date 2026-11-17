<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Patronato\Persistencia\Models\Sticker;

class StickerRepositorio extends Repositorio
{
    /**
     * Sticker que todavía pertenece al vehículo, reservado hasta terminar la transacción
     */
    public function bloquearVigenteDe(int $vehiculoId): ?Sticker
    {
        return Sticker::query()
            ->lockForUpdate()
            ->where('vehiculo_id', $vehiculoId)
            ->whereIn('estado', Sticker::VIGENTES)
            ->first();
    }

    /**
     * Vuelve a leer el sticker reservándolo hasta terminar la transacción
     */
    public function bloquearRegistro(int $stickerId): ?Sticker
    {
        return Sticker::query()->lockForUpdate()->find($stickerId);
    }

    /**
     * Indica si otro sticker activo del residencial usa el número
     */
    public function existeNumeroActivo(string $numero, ?int $exceptoId = null): bool
    {
        return Sticker::query()
            ->where('estado', Sticker::ACTIVO)
            ->where('numero', $numero)
            ->when($exceptoId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Sticker
    {
        return Sticker::query()->create($datos);
    }
}
