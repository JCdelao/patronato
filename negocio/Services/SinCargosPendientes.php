<?php

namespace Patronato\Negocio\Services;

use Illuminate\Support\Collection;
use Patronato\Negocio\Contratos\CargosNoIntegrados;
use Patronato\Negocio\Contratos\CargosPendientes;

/**
 * Implementación temporal mientras no exista la generación de cargos
 *
 * No devuelve una lista vacía para no confundir "sin pendientes" con "sin integrar".
 */
class SinCargosPendientes implements CargosPendientes
{
    public function pendientesDeVivienda(int $viviendaId): Collection
    {
        throw new CargosNoIntegrados;
    }
}
