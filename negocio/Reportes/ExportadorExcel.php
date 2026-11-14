<?php

namespace Patronato\Negocio\Reportes;

use Patronato\Negocio\Contratos\GeneradorHojaCalculo;

/**
 * Reporte de cobros en Excel: una hoja con el resumen y otra con el detalle
 *
 * Los montos van como números para que se puedan sumar en Excel.
 */
class ExportadorExcel implements ExportadorReporte
{
    public function __construct(private GeneradorHojaCalculo $generador) {}

    public function exportar(ReporteCobros $reporte): string
    {
        $resumen = [];

        foreach ($reporte->resumen as $fila) {
            $resumen[] = [$fila['cobrador'], $fila['periodo'], $fila['cobros'], (float) $fila['total']];
        }

        foreach ($reporte->subtotales as $fila) {
            $resumen[] = ["Subtotal {$fila['cobrador']}", null, $fila['cobros'], (float) $fila['total']];
        }

        $resumen[] = ['Total', null, $reporte->totalCobros, (float) $reporte->total];

        if ($reporte->anulados > 0) {
            $resumen[] = ['Anulados (no suman)', null, $reporte->anulados, (float) $reporte->montoAnulado];
        }

        $detalle = [];

        foreach ($reporte->detalle as $cobro) {
            $detalle[] = [
                $cobro['fecha_pago'],
                $cobro['numero_recibo'],
                $cobro['cobrador'],
                $cobro['vivienda'],
                (float) $cobro['monto'],
                $cobro['estado'],
                $cobro['estado_caja'],
            ];
        }

        return $this->generador->generar([
            'Resumen' => [
                'encabezados' => ['Cobrador', $reporte->filtros['agrupacion'] === ConstructorReporteCobros::POR_MES ? 'Mes' : 'Día', 'Cobros', 'Total (L)'],
                'filas' => $resumen,
            ],
            'Detalle' => [
                'encabezados' => ['Fecha', 'Recibo', 'Cobrador', 'Vivienda', 'Monto (L)', 'Estado del cobro', 'Estado de la caja'],
                'filas' => $detalle,
            ],
        ]);
    }

    public function tipoContenido(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function extension(): string
    {
        return 'xlsx';
    }
}
