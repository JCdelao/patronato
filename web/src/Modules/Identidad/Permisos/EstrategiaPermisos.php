<?php

namespace Patronato\Modules\Identidad\Permisos;

use App\Models\User;

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
