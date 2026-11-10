<?php

namespace Patronato\Modules\Finanzas\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Patronato\Modules\Finanzas\Models\Caja;

/**
 * Consulta de cajas de los cobradores
 *
 * La apertura y el cierre los hace el tesorero en otra funcionalidad.
 */
class CajaService
{
    /**
     * Caja abierta del cobrador, si tiene una
     */
    public function abiertaDe(User $cobrador): ?Caja
    {
        return $this->abiertas($cobrador)->first();
    }

    /**
     * Caja abierta del cobrador bloqueada hasta terminar la transacción
     *
     * El cierre de caja debe bloquear la misma fila para no cruzarse con un cobro.
     */
    public function bloquearAbiertaDe(User $cobrador): ?Caja
    {
        return $this->abiertas($cobrador)->lockForUpdate()->first();
    }

    /**
     * @return Builder<Caja>
     */
    private function abiertas(User $cobrador): Builder
    {
        return Caja::query()
            ->where('residencial_id', $cobrador->residencial_id)
            ->where('cobrador_id', $cobrador->id)
            ->where('estado', Caja::ABIERTA);
    }
}
