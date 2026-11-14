<?php

namespace Patronato\Negocio\Reportes;

/**
 * Cobros en efectivo agrupados por cobrador y período, con sus totales
 *
 * Los montos son textos con dos decimales. Solo los cobros confirmados suman;
 * los anulados se cuentan aparte y aparecen en el detalle con su estado.
 */
final class ReporteCobros
{
    /**
     * @param  array{desde: string|null, hasta: string|null, cobrador: string|null, estado_caja: string|null, agrupacion: string}  $filtros
     * @param  list<array{cobrador_id: int, cobrador: string, periodo: string, cobros: int, total: string}>  $resumen
     * @param  list<array{cobrador_id: int, cobrador: string, cobros: int, total: string}>  $subtotales
     * @param  list<array{pago_id: int, numero_recibo: string|null, fecha_pago: string, monto: string, estado: string, cobrador_id: int, cobrador: string, vivienda: string, estado_caja: string}>  $detalle
     */
    public function __construct(
        public readonly array $filtros,
        public readonly array $resumen,
        public readonly array $subtotales,
        public readonly int $totalCobros,
        public readonly string $total,
        public readonly int $anulados,
        public readonly string $montoAnulado,
        public readonly array $detalle,
    ) {}

    /**
     * Datos para la pantalla, que no lleva el detalle
     *
     * @return array<string, mixed>
     */
    public function resumenParaPantalla(): array
    {
        return [
            'filtros' => $this->filtros,
            'resumen' => $this->resumen,
            'subtotales' => $this->subtotales,
            'total_cobros' => $this->totalCobros,
            'total' => $this->total,
            'anulados' => $this->anulados,
            'monto_anulado' => $this->montoAnulado,
        ];
    }
}
