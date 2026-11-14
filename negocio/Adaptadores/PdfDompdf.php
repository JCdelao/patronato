<?php

namespace Patronato\Negocio\Adaptadores;

use Barryvdh\DomPDF\Facade\Pdf;
use Patronato\Negocio\Contratos\GeneradorPdf;

/**
 * Genera los PDF con dompdf, que funciona solo con PHP
 */
class PdfDompdf implements GeneradorPdf
{
    public function generar(string $vista, array $datos): string
    {
        return Pdf::loadView($vista, $datos)->setPaper('letter')->output();
    }
}
