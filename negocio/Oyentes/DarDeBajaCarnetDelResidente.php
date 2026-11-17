<?php

namespace Patronato\Negocio\Oyentes;

use Patronato\Negocio\Eventos\ResidenteDadoDeBaja;
use Patronato\Negocio\Services\CarnetService;

/**
 * Un residente dado de baja no conserva su carnet
 */
class DarDeBajaCarnetDelResidente
{
    public function __construct(private CarnetService $carnets) {}

    public function handle(ResidenteDadoDeBaja $evento): void
    {
        $this->carnets->darDeBajaDeResidente($evento->usuario, $evento->residente);
    }
}
