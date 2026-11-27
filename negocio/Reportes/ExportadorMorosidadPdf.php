<?php

namespace Patronato\Negocio\Reportes;

use Patronato\Negocio\Contratos\GeneradorPdf;

/**
 * Reporte de morosidad en PDF
 */
class ExportadorMorosidadPdf implements ExportadorMorosidad
{
    public function __construct(private GeneradorPdf $generador) {}

    public function exportar(ReporteMorosidad $reporte): string
    {
        return $this->generador->generar('pdf.reporte-morosidad', ['reporte' => $reporte]);
    }

    public function tipoContenido(): string
    {
        return 'application/pdf';
    }

    public function extension(): string
    {
        return 'pdf';
    }
}
