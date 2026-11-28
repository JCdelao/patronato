<?php

namespace Patronato\Negocio\Reportes;

/**
 * Recaudación confirmada por período y forma de pago, con los pendientes y lo sin efecto aparte
 *
 * Los montos son textos con dos decimales.
 */
final class ReporteRecaudacion
{
    /**
     * @param  array{desde: string, hasta: string, cobrador_id: int|null, cobrador: string|null, agrupacion: string}  $filtros
     * @param  list<array{periodo: string, pagos: int, efectivo: string, transferencia: string, total: string}>  $periodos
     * @param  array{pagos: int, efectivo: string, transferencia: string, total: string}  $totales
     * @param  array{pagos: int, monto: string, estados: list<array{estado: string, pagos: int, monto: string}>}  $pendientes
     * @param  list<array{estado: string, pagos: int, monto: string}>  $sinEfecto
     */
    public function __construct(
        public readonly array $filtros,
        public readonly array $periodos,
        public readonly array $totales,
        public readonly array $pendientes,
        public readonly array $sinEfecto,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function paraPantalla(): array
    {
        return [
            'periodos' => $this->periodos,
            'totales' => $this->totales,
            'pendientes' => $this->pendientes,
            'sin_efecto' => $this->sinEfecto,
        ];
    }
}
