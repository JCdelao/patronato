<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Support\Facades\DB;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\PagoAplicacion;

class PagoRepositorio
{
    // Identifica los bloqueos de pagos entre los bloqueos de PostgreSQL
    private const ESPACIO_BLOQUEO = 19001;

    /**
     * Mayor número de recibo generado por el sistema en el residencial
     *
     * Los pagos migrados no cuentan, porque su recibo puede tener cualquier forma.
     */
    public function ultimoNumeroRecibo(int $residencialId): int
    {
        return (int) Pago::query()
            ->withoutGlobalScope('residencial')
            ->where('residencial_id', $residencialId)
            ->where('origen', '<>', 'migracion')
            ->whereNotNull('numero_recibo')
            ->max(DB::raw('CAST(numero_recibo AS BIGINT)'));
    }

    /**
     * Ordena en fila los registros de pagos del residencial hasta terminar la transacción
     */
    public function bloquear(int $residencialId): void
    {
        // SQLite ya permite una sola escritura a la vez
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::ESPACIO_BLOQUEO, $residencialId]);
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Pago
    {
        return Pago::query()->create($datos);
    }

    /**
     * Guarda qué parte del pago cubre un cargo
     *
     * @param  array<string, mixed>  $datos
     */
    public function aplicar(array $datos): PagoAplicacion
    {
        return PagoAplicacion::query()->create($datos);
    }
}
