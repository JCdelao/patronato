<?php

namespace Patronato\Negocio\Oyentes;

use Patronato\Negocio\Eventos\PagoAnulado;
use Patronato\Negocio\Services\IngresoService;

/**
 * Al anularse un pago, su ingreso queda anulado con el mismo motivo
 */
class AnularIngresoDeCobro
{
    public function __construct(private IngresoService $ingresos) {}

    public function handle(PagoAnulado $evento): void
    {
        $this->ingresos->anularDeCobro($evento->usuario, $evento->pago, $evento->motivo);
    }
}
