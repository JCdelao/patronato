<?php

namespace Patronato\Persistencia\Repositorios;

use Patronato\Persistencia\Models\Caja;

class CajaRepositorio
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
}
