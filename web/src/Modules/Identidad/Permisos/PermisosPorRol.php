<?php

namespace Patronato\Modules\Identidad\Permisos;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Usa la matriz de permisos del rol en su residencial
 */
class PermisosPorRol implements EstrategiaPermisos
{
    public function puede(User $usuario, string $modulo, string $accion): bool
    {
        return DB::table('rol_permisos')
            ->where('residencial_id', $usuario->residencial_id)
            ->where('rol_id', $usuario->rol_id)
            ->where('modulo', $modulo)
            ->where('accion', $accion)
            ->exists();
    }

    public function permisos(User $usuario): array
    {
        return self::deRol((int) $usuario->residencial_id, (int) $usuario->rol_id);
    }

    /**
     * @return array<int, string>
     */
    public static function deRol(int $residencialId, int $rolId): array
    {
        return DB::table('rol_permisos')
            ->where('residencial_id', $residencialId)
            ->where('rol_id', $rolId)
            ->orderBy('modulo')
            ->orderBy('accion')
            ->get(['modulo', 'accion'])
            ->map(fn (object $fila): string => "{$fila->modulo}.{$fila->accion}")
            ->values()
            ->all();
    }
}
