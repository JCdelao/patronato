<?php

namespace Patronato\Negocio\Contratos;

/**
 * Convierte una vista en un documento PDF
 *
 * Negocio depende de esta interfaz y no de la librería de PDF.
 * La implementación se registra en AppServiceProvider.
 */
interface GeneradorPdf
{
    /**
     * Devuelve el contenido del PDF generado con la vista y sus datos
     *
     * @param  array<string, mixed>  $datos
     */
    public function generar(string $vista, array $datos): string;
}
