<?php

namespace Patronato\Negocio\Reportes;

use Patronato\Negocio\Contratos\GeneradorPdf;

/**
 * Reporte de cobros en PDF, con el resumen y el detalle de cada cobro
 */
class ExportadorPdf implements ExportadorReporte
{
    public function __construct(private GeneradorPdf $generador) {}

    public function exportar(ReporteCobros $reporte): string
    {
        return $this->generador->generar('pdf.reporte-cobros', ['reporte' => $reporte]);
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
