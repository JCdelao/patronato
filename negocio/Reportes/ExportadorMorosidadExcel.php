<?php

namespace Patronato\Negocio\Reportes;

use Patronato\Negocio\Contratos\GeneradorHojaCalculo;

/**
 * Reporte de morosidad en Excel, una fila por vivienda
 *
 * Los montos van como números para que se puedan sumar en Excel.
 */
class ExportadorMorosidadExcel implements ExportadorMorosidad
{
    public function __construct(private GeneradorHojaCalculo $generador) {}

    public function exportar(ReporteMorosidad $reporte): string
    {
        $filas = [];

        foreach ($reporte->viviendas as $vivienda) {
            $filas[] = [
                $vivienda['codigo'],
                $vivienda['responsable'],
                $vivienda['meses_adeudados'],
                $vivienda['deuda_desde'],
                $vivienda['meses_vencidos'],
                (float) $vivienda['vencido'],
                (float) $vivienda['saldo'],
                (float) $vivienda['por_verificar'],
                $vivienda['estado'] === 'en_mora' ? 'En mora' : 'Al día',
                $vivienda['activa'] ? 'Activa' : 'Inactiva',
            ];
        }

        $filas[] = ['Total', null, null, null, null, (float) $reporte->totalVencido, (float) $reporte->total, null, null, null];

        return $this->generador->generar([
            'Morosidad' => [
                'encabezados' => ['Vivienda', 'Responsable de pago', 'Meses adeudados', 'Deuda desde', 'Meses vencidos', 'Vencido (L)', 'Saldo (L)', 'Por verificar (L)', 'Estado', 'Vivienda activa'],
                'filas' => $filas,
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
