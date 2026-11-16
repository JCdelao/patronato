<?php

namespace Patronato\Negocio\Oyentes;

use Patronato\Negocio\Eventos\PagoConfirmado;
use Patronato\Negocio\Services\IngresoService;

/**
 * Cada pago confirmado entra como ingreso de cuotas
 */
class RegistrarIngresoDeCobro
{
    public function __construct(private IngresoService $ingresos) {}

    public function handle(PagoConfirmado $evento): void
    {
        $this->ingresos->registrarDeCobro($evento->usuario, $evento->pago);
    }
}
