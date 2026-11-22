<?php

namespace Patronato\Negocio\Oyentes;

use Patronato\Negocio\Eventos\CargosGenerados;
use Patronato\Negocio\Services\AplicacionPagoService;

// Patrón Observer (comportamiento): oyente que reacciona al evento CargosGenerados
/**
 * Aplica el saldo a favor de las viviendas a los cargos pendientes, incluidos los recién generados
 *
 * Corre dentro de la transacción de la generación: si falla, la generación tampoco se guarda.
 *
 * Limitaciones conocidas:
 * - Si se anula un pago, el saldo a favor de otros pagos no cubre en ese momento los cargos
 *   que quedan pendientes; los cubre en la siguiente generación, aunque no cree cargos nuevos.
 * - La migración que separa las aplicaciones por origen no se puede revertir una vez que
 *   existe una aplicación de saldo a favor.
 */
class AplicarSaldoAFavor
{
    public function __construct(private AplicacionPagoService $aplicaciones) {}

    public function handle(CargosGenerados $evento): void
    {
        $this->aplicaciones->aplicarSaldoAFavor($evento->residencialId, $evento->usuario);
    }
}
