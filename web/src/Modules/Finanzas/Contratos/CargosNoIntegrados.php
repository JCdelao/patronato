<?php

namespace Patronato\Modules\Finanzas\Contratos;

use RuntimeException;

/**
 * Indica que la consulta de períodos pendientes todavía no está disponible
 */
class CargosNoIntegrados extends RuntimeException
{
    public const MENSAJE = 'La consulta de períodos pendientes está pendiente de integración con la generación de cargos.';

    public function __construct()
    {
        parent::__construct(self::MENSAJE);
    }
}
