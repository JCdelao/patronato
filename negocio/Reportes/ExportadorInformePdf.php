<?php

namespace Patronato\Negocio\Reportes;

use Patronato\Negocio\Contratos\GeneradorPdf;

/**
 * Informe financiero en PDF
 */
class ExportadorInformePdf implements ExportadorInforme
{
    public function __construct(private GeneradorPdf $generador) {}

    public function exportar(InformeFinanciero $informe): string
    {
        return $this->generador->generar('pdf.informe-financiero', ['informe' => $informe]);
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
