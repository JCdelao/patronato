<?php

namespace Patronato\Negocio\Reportes;

// Patrón Strategy (comportamiento): ExportadorPdf y ExportadorExcel son dos formas intercambiables de exportar
/**
 * Convierte el reporte de cobros en un archivo descargable
 *
 * Cada formato es una implementación; se eligen en ReporteCobrosService::exportador().
 */
interface ExportadorReporte
{
    /**
     * Contenido del archivo
     */
    public function exportar(ReporteCobros $reporte): string;

    /**
     * Tipo MIME del archivo
     */
    public function tipoContenido(): string;

    /**
     * Extensión del archivo, sin punto
     */
    public function extension(): string;
}
