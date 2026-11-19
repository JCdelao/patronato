<?php

namespace Patronato\Negocio\Permisos;

use Patronato\Persistencia\Models\User;

// Patrón Strategy (comportamiento): cada clase que la implementa calcula los permisos a su manera
// Principio L (sustitución de Liskov): AccesoTotal, PermisosPorRol y SinPermisos se pueden usar una en lugar de otra
/**
 * Forma de calcular los permisos de un usuario
 */
interface EstrategiaPermisos
{
    public function puede(User $usuario, string $modulo, string $accion): bool;

    /**
     * @return array<int, string>
     */
    public function permisos(User $usuario): array;
}
