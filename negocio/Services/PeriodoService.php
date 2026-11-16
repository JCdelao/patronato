<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

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

    /**
     * Fecha de hoy, en la hora de Honduras
     */
    public function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONA_LOCAL)->startOfDay();
    }

    /**
     * Día del mes de hoy, en la hora de Honduras
     */
    public function diaDeHoy(): int
    {
        return CarbonImmutable::now(self::ZONA_LOCAL)->day;
    }

    /**
     * Convierte el mes elegido (AAAA-MM) en el período desde el que rige un monto
     *
     * @throws ValidationException si el mes ya pasó
     */
    public function desdeMes(string $mes): CarbonImmutable
    {
        $desde = CarbonImmutable::createFromFormat('!Y-m', $mes);

        if ($desde === null || $desde->toDateString() < $this->actual()->toDateString()) {
            throw ValidationException::withMessages([
                'vigente_desde' => 'El monto no puede regir desde un mes que ya pasó.',
            ]);
        }

        return $desde;
    }
}
