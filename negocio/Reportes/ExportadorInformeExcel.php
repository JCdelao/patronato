<?php

namespace Patronato\Negocio\Reportes;

use Patronato\Negocio\Contratos\GeneradorHojaCalculo;

/**
 * Informe financiero en Excel: una hoja con el resumen y, en el anual, otra con cada mes
 *
 * Los montos van como números para que se puedan sumar en Excel.
 */
class ExportadorInformeExcel implements ExportadorInforme
{
    public function __construct(private GeneradorHojaCalculo $generador) {}

    public function exportar(InformeFinanciero $informe): string
    {
        $resumen = [[$informe->titulo, null, null], ['Saldo inicial', null, (float) $informe->saldoInicial]];

        if ($informe->apertura !== null) {
            $resumen[] = ['Saldo de apertura', $informe->apertura['fecha'], (float) $informe->apertura['monto']];
        }

        foreach ($informe->ingresos as $fila) {
            $resumen[] = ['Ingreso', $fila['categoria'], (float) $fila['total']];
        }

        $resumen[] = ['Total de ingresos', null, (float) $informe->totalIngresos];

        foreach ($informe->egresos as $fila) {
            $resumen[] = ['Egreso', $fila['categoria'], (float) $fila['total']];
        }

        $resumen[] = ['Total de egresos', null, (float) $informe->totalEgresos];
        $resumen[] = ['Saldo final', null, (float) $informe->saldoFinal];

        $hojas = [
            'Resumen' => ['encabezados' => ['Concepto', 'Categoría', 'Monto (L)'], 'filas' => $resumen],
        ];

        if ($informe->meses !== []) {
            $hojas['Por mes'] = [
                'encabezados' => ['Mes', 'Saldo inicial (L)', 'Apertura (L)', 'Ingresos (L)', 'Egresos (L)', 'Saldo final (L)'],
                'filas' => array_map(fn (array $mes): array => [
                    $mes['mes'],
                    (float) $mes['saldo_inicial'],
                    $mes['apertura'] === null ? null : (float) $mes['apertura'],
                    (float) $mes['ingresos'],
                    (float) $mes['egresos'],
                    (float) $mes['saldo_final'],
                ], $informe->meses),
            ];
        }

        return $this->generador->generar($hojas);
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
