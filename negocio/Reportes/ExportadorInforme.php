<?php

namespace Patronato\Negocio\Reportes;

/**
 * Convierte el informe financiero en un archivo descargable
 *
 * Cada formato es una implementación; se eligen en InformeFinancieroService::exportador().
 */
interface ExportadorInforme
{
    /**
     * Contenido del archivo
     */
    public function exportar(InformeFinanciero $informe): string;

    /**
     * Tipo MIME del archivo
     */
    public function tipoContenido(): string;

    /**
     * Extensión del archivo, sin punto
     */
    public function extension(): string;
}
