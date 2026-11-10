<?php

namespace Patronato\Modules\Finanzas\Services;

use Illuminate\Support\Collection;
use Patronato\Modules\Finanzas\Contratos\CargosNoIntegrados;
use Patronato\Modules\Finanzas\Contratos\CargosPendientes;

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
