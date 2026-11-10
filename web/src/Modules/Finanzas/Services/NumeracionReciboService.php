<?php

namespace Patronato\Modules\Finanzas\Services;

use Illuminate\Support\Facades\DB;
use LogicException;
use Patronato\Modules\Finanzas\Models\Pago;

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
    // Identifica los bloqueos de pagos entre los bloqueos de PostgreSQL
    private const ESPACIO_BLOQUEO = 19001;

    /**
     * Devuelve el siguiente número de recibo del residencial
     */
    public function siguiente(int $residencialId): string
    {
        $this->bloquear($residencialId);

        $ultimo = Pago::query()
            ->withoutGlobalScope('residencial')
            ->where('residencial_id', $residencialId)
            ->where('origen', '<>', 'migracion')
            ->whereNotNull('numero_recibo')
            ->max(DB::raw('CAST(numero_recibo AS BIGINT)'));

        return $this->formatear((int) $ultimo + 1);
    }

    /**
     * Ordena en fila los registros de pagos del residencial hasta terminar la transacción
     */
    public function bloquear(int $residencialId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La numeración de recibos debe usarse dentro de una transacción.');
        }

        // SQLite ya permite una sola escritura a la vez
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::ESPACIO_BLOQUEO, $residencialId]);
        }
    }

    private function formatear(int $numero): string
    {
        return (string) $numero;
    }
}
