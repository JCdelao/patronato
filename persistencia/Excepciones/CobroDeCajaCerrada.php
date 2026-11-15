<?php

namespace Patronato\Persistencia\Excepciones;

use LogicException;

/**
 * Se intentó cambiar o borrar un cobro de una caja cerrada
 */
class CobroDeCajaCerrada extends LogicException
{
    public function __construct()
    {
        parent::__construct('Los cobros de una caja cerrada no se pueden editar. La corrección se hace con una anulación.');
    }
}
