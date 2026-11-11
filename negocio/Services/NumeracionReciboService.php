<?php

namespace Patronato\Negocio\Services;

use LogicException;
use Patronato\Persistencia\Repositorios\PagoRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Numeración de los recibos de pago
 *
 * Reglas:
 * - La numeración es propia de cada residencial y empieza en 1.
 * - El siguiente número es el mayor recibo generado por el sistema más uno.
 *   Los pagos migrados no cuentan, porque su recibo puede tener cualquier forma.
 * - Un pago anulado conserva su número y sigue contando.
 * - Se llama dentro de la transacción que guarda el pago: si la transacción
 *   se revierte, el número no se consume.
 * - En PostgreSQL un bloqueo por residencial impide que dos registros simultáneos
 *   calculen el mismo número. El índice único (residencial_id, numero_recibo)
 *   es la segunda protección.
 * - Se guarda solo el número; el formato definitivo se cambia en formatear().
 */
class NumeracionReciboService
{
    public function __construct(
        private PagoRepositorio $pagos,
        private Transaccion $transaccion,
    ) {}

    /**
     * Devuelve el siguiente número de recibo del residencial
     */
    public function siguiente(int $residencialId): string
    {
        $this->bloquear($residencialId);

        return $this->formatear($this->pagos->ultimoNumeroRecibo($residencialId) + 1);
    }

    /**
     * Ordena en fila los registros de pagos del residencial hasta terminar la transacción
     */
    public function bloquear(int $residencialId): void
    {
        if (! $this->transaccion->activa()) {
            throw new LogicException('La numeración de recibos debe usarse dentro de una transacción.');
        }

        $this->pagos->bloquear($residencialId);
    }

    private function formatear(int $numero): string
    {
        return (string) $numero;
    }
}
