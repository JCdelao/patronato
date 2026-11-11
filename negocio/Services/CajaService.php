<?php

namespace Patronato\Negocio\Services;

use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\CajaRepositorio;

/**
 * Consulta de cajas de los cobradores
 *
 * La apertura y el cierre los hace el tesorero en otra funcionalidad.
 */
class CajaService
{
    public function __construct(private CajaRepositorio $cajas) {}

    /**
     * Caja abierta del cobrador, si tiene una
     */
    public function abiertaDe(User $cobrador): ?Caja
    {
        return $this->cajas->abiertaDe((int) $cobrador->residencial_id, $cobrador->id);
    }

    /**
     * Caja abierta del cobrador bloqueada hasta terminar la transacción
     *
     * El cierre de caja debe bloquear la misma fila para no cruzarse con un cobro.
     */
    public function bloquearAbiertaDe(User $cobrador): ?Caja
    {
        return $this->cajas->abiertaDe((int) $cobrador->residencial_id, $cobrador->id, bloquear: true);
    }
}
