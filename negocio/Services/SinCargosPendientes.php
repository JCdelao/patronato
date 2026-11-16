<?php

namespace Patronato\Negocio\Services;

use Illuminate\Support\Collection;
use Patronato\Negocio\Contratos\CargosNoIntegrados;
use Patronato\Negocio\Contratos\CargosPendientes;

/**
 * Implementación que avisa que la consulta de cargos no está disponible
 *
 * Ya no se registra en AppServiceProvider; queda para probar el aviso del cobro.
 * No devuelve una lista vacía para no confundir "sin pendientes" con "sin integrar".
 */
class SinCargosPendientes implements CargosPendientes
{
    public function pendientesDeVivienda(int $viviendaId): Collection
    {
        throw new CargosNoIntegrados;
    }
}
