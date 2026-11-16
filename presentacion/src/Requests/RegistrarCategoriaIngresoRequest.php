<?php

namespace Patronato\Presentacion\Requests;

use Patronato\Persistencia\Models\CategoriaFinanciera;

class RegistrarCategoriaIngresoRequest extends RegistrarCategoriaEgresoRequest
{
    /**
     * Tipo de las categorías entre las que no se puede repetir el nombre
     */
    protected function tipo(): string
    {
        return CategoriaFinanciera::INGRESO;
    }
}
