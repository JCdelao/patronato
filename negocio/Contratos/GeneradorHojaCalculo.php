<?php

namespace Patronato\Negocio\Contratos;

/**
 * Escribe un libro de Excel con una o varias hojas
 *
 * Negocio depende de esta interfaz y no de la librería de hojas de cálculo.
 * La implementación se registra en AppServiceProvider.
 */
interface GeneradorHojaCalculo
{
    /**
     * Devuelve el contenido del archivo .xlsx
     *
     * La primera fila de cada hoja son los encabezados, en negrita.
     *
     * @param  array<string, array{encabezados: list<string>, filas: list<list<string|int|float|null>>}>  $hojas  por nombre de hoja
     */
    public function generar(array $hojas): string;
}
