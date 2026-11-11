<?php

namespace Patronato\Negocio\Permisos;

use Patronato\Persistencia\Models\User;

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
