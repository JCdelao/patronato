<?php

namespace Patronato\Negocio\Reportes;

/**
 * Convierte el reporte de morosidad en un archivo descargable
 *
 * Cada formato es una implementación; se eligen en ReporteMorosidadService::exportador().
 */
interface ExportadorMorosidad
{
    /**
     * Contenido del archivo
     */
    public function exportar(ReporteMorosidad $reporte): string;

    /**
     * Tipo MIME del archivo
     */
    public function tipoContenido(): string;

    /**
     * Extensión del archivo, sin punto
     */
    public function extension(): string;
}
