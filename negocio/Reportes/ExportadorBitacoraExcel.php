<?php

namespace Patronato\Negocio\Reportes;

use Patronato\Negocio\Contratos\GeneradorHojaCalculo;

/**
 * Bitácora de accesos en Excel: una fila por evento y una hoja con los filtros y totales
 */
class ExportadorBitacoraExcel implements ExportadorBitacora
{
    public function __construct(private GeneradorHojaCalculo $generador) {}

    public function exportar(BitacoraAccesos $bitacora): string
    {
        $eventos = [];

        foreach ($bitacora->eventos as $evento) {
            $eventos[] = [
                $evento['fecha'],
                BitacoraTextos::tipo($evento['tipo']),
                $evento['guardia'],
                BitacoraTextos::turno($evento['turno']),
                $evento['vivienda'],
                $evento['medio'],
                $evento['visitante'] ?? $evento['categoria'],
                $evento['placa'],
                $evento['detalle'],
                $evento['urgente'] ? 'Sí' : null,
            ];
        }

        $filtros = $bitacora->filtros;

        return $this->generador->generar([
            'Bitácora' => [
                'encabezados' => ['Fecha y hora', 'Tipo', 'Guardia', 'Turno', 'Vivienda', 'Medio de entrada', 'Visitante o tipo de incidencia', 'Placa', 'Motivo o qué pasó', 'Urgente'],
                'filas' => $eventos,
            ],
            'Resumen' => [
                'encabezados' => ['Dato', 'Valor'],
                'filas' => [
                    ['Desde', $filtros['desde']],
                    ['Hasta', $filtros['hasta']],
                    ['Tipo', BitacoraTextos::tipo($filtros['tipo'])],
                    ['Turno', BitacoraTextos::turno($filtros['turno'], 'Todos')],
                    ['Guardia', $filtros['guardia'] ?? 'Todos'],
                    ['Vivienda', $filtros['vivienda'] ?? 'Todas'],
                    ['Medio de entrada', $filtros['medio'] ?? 'Todos'],
                    ['Entradas', $bitacora->totales['entrada']],
                    ['Salidas', $bitacora->totales['salida']],
                    ['Incidencias', $bitacora->totales['incidencia']],
                ],
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
