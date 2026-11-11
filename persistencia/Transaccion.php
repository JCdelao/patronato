<?php

namespace Patronato\Persistencia;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Agrupa varias operaciones para que se guarden todas o ninguna
 */
class Transaccion
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operaciones
     * @return T
     */
    public function ejecutar(Closure $operaciones): mixed
    {
        return DB::transaction($operaciones);
    }

    /**
     * Indica si hay una transacción abierta
     */
    public function activa(): bool
    {
        return DB::transactionLevel() > 0;
    }
}
