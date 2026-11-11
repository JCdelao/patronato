<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Matriz de permisos de cada rol en cada residencial
 */
class RolPermisoRepositorio
{
    public function existe(int $residencialId, int $rolId, string $modulo, string $accion): bool
    {
        return $this->deRolQuery($residencialId, $rolId)
            ->where('modulo', $modulo)
            ->where('accion', $accion)
            ->exists();
    }

    /**
     * Permisos del rol como "modulo.accion"
     *
     * @return array<int, string>
     */
    public function deRol(int $residencialId, int $rolId): array
    {
        return $this->deRolQuery($residencialId, $rolId)
            ->orderBy('modulo')
            ->orderBy('accion')
            ->get(['modulo', 'accion'])
            ->map(fn (object $fila): string => "{$fila->modulo}.{$fila->accion}")
            ->values()
            ->all();
    }

    /**
     * Agrega permisos y omite los que ya existen
     *
     * @param  array<int, array{residencial_id: int, rol_id: int, modulo: string, accion: string}>  $filas
     */
    public function agregar(array $filas): void
    {
        DB::table('rol_permisos')->insertOrIgnore($filas);
    }

    /**
     * Reemplaza todos los permisos del rol
     *
     * @param  array<int, array{residencial_id: int, rol_id: int, modulo: string, accion: string}>  $filas
     */
    public function reemplazar(int $residencialId, int $rolId, array $filas): void
    {
        $this->deRolQuery($residencialId, $rolId)->delete();

        DB::table('rol_permisos')->insert($filas);
    }

    private function deRolQuery(int $residencialId, int $rolId): Builder
    {
        return DB::table('rol_permisos')
            ->where('residencial_id', $residencialId)
            ->where('rol_id', $rolId);
    }
}
