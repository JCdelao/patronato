<?php

namespace Patronato\Negocio\Reportes;

/**
 * Convierte la bitácora de accesos en un archivo descargable
 *
 * Cada formato es una implementación; se eligen en BitacoraAccesosService::exportador().
 */
interface ExportadorBitacora
{
    /**
     * Contenido del archivo
     */
    public function exportar(BitacoraAccesos $bitacora): string;

    /**
     * Tipo MIME del archivo
     */
    public function tipoContenido(): string;

    /**
     * Extensión del archivo, sin punto
     */
    public function extension(): string;
}
