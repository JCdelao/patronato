<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;

/**
 * Períodos de cobro según la hora de Honduras
 */
class PeriodoService
{
    private const ZONA_LOCAL = 'America/Tegucigalpa';

    /**
     * Primer día del mes en curso
     */
    public function actual(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONA_LOCAL)->startOfMonth();
    }
}
