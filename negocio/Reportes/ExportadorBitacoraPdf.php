<?php

namespace Patronato\Negocio\Reportes;

use Patronato\Negocio\Contratos\GeneradorPdf;

/**
 * Bitácora de accesos en PDF, con los filtros, los totales y cada evento
 */
class ExportadorBitacoraPdf implements ExportadorBitacora
{
    public function __construct(private GeneradorPdf $generador) {}

    public function exportar(BitacoraAccesos $bitacora): string
    {
        return $this->generador->generar('pdf.bitacora-accesos', ['bitacora' => $bitacora]);
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
