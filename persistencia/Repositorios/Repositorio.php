<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Model;

/**
 * Operaciones comunes sobre un registro ya cargado
 */
abstract class Repositorio
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Model $modelo, array $datos): void
    {
        $modelo->update($datos);
    }

    public function eliminar(Model $modelo): void
    {
        $modelo->delete();
    }
}
