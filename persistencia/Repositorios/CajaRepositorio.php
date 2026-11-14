<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\Caja;

class CajaRepositorio extends Repositorio
{
    /**
     * Caja abierta del cobrador; con bloqueo la reserva hasta terminar la transacción
     */
    public function abiertaDe(int $residencialId, int $cobradorId, bool $bloquear = false): ?Caja
    {
        return Caja::query()
            ->where('residencial_id', $residencialId)
            ->where('cobrador_id', $cobradorId)
            ->where('estado', Caja::ABIERTA)
            ->when($bloquear, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * Indica si el cobrador tiene una caja abierta en otro residencial
     *
     * La base permite una sola caja abierta por cobrador en todo el sistema.
     */
    public function tieneAbiertaFueraDe(int $residencialId, int $cobradorId): bool
    {
        return Caja::query()
            ->withoutGlobalScope('residencial')
            ->where('cobrador_id', $cobradorId)
            ->where('residencial_id', '<>', $residencialId)
            ->where('estado', Caja::ABIERTA)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Caja
    {
        return Caja::query()->create($datos);
    }

    /**
     * Vuelve a leer la caja reservándola hasta terminar la transacción
     */
    public function bloquear(int $cajaId): ?Caja
    {
        return Caja::query()->lockForUpdate()->find($cajaId);
    }

    /**
     * Cajas abiertas del residencial con su cobrador y sus cobros
     *
     * @return Collection<int, Caja>
     */
    public function abiertas(): Collection
    {
        return Caja::query()
            ->where('estado', Caja::ABIERTA)
            ->with(['cobrador', 'pagos'])
            ->orderBy('abierta_en')
            ->orderBy('id')
            ->get();
    }

    /**
     * Últimas cajas cerradas del residencial
     *
     * @return Collection<int, Caja>
     */
    public function cerradasRecientes(int $limite): Collection
    {
        return Caja::query()
            ->where('estado', Caja::CERRADA)
            ->with(['cobrador', 'cerradaPor', 'pagos'])
            ->latest('cerrada_en')
            ->latest('id')
            ->limit($limite)
            ->get();
    }

    /**
     * Carga lo que muestra el cierre: cobrador, quién cerró y cobros con su vivienda
     */
    public function conDetalle(Caja $caja): Caja
    {
        return $caja->load(['cobrador', 'cerradaPor', 'pagos' => fn ($query) => $query->with('vivienda')->orderBy('id')]);
    }
}
