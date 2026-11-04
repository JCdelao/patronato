<?php

namespace Patronato\Modules\Identidad\Permisos;

use App\Models\User;

/**
 * Permite todo el catálogo, sin depender de la matriz
 */
class AccesoTotal implements EstrategiaPermisos
{
    /**
     * @param  array<int, string>  $catalogo
     */
    public function __construct(private array $catalogo) {}

    public function puede(User $usuario, string $modulo, string $accion): bool
    {
        return in_array("{$modulo}.{$accion}", $this->catalogo, true);
    }

    public function permisos(User $usuario): array
    {
        return $this->catalogo;
    }
}
