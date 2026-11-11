<?php

namespace Patronato\Negocio\Permisos;

use Patronato\Persistencia\Models\User;

/**
 * Niega todo a quien no tiene residencial o rol
 */
class SinPermisos implements EstrategiaPermisos
{
    public function puede(User $usuario, string $modulo, string $accion): bool
    {
        return false;
    }

    public function permisos(User $usuario): array
    {
        return [];
    }
}
