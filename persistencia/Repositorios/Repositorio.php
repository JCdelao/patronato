<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Model;

// Patrón Repository: las consultas a la base de datos quedan en los repositorios
// Principio O (abierto/cerrado): un repositorio nuevo hereda estas operaciones sin cambiar esta clase
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
