<?php

namespace Patronato\Negocio\Permisos;

use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\RolPermisoRepositorio;

/**
 * Usa la matriz de permisos del rol en su residencial
 */
class PermisosPorRol implements EstrategiaPermisos
{
    public function __construct(private RolPermisoRepositorio $matriz) {}

    public function puede(User $usuario, string $modulo, string $accion): bool
    {
        return $this->matriz->existe((int) $usuario->residencial_id, (int) $usuario->rol_id, $modulo, $accion);
    }

    public function permisos(User $usuario): array
    {
        return $this->matriz->deRol((int) $usuario->residencial_id, (int) $usuario->rol_id);
    }
}
