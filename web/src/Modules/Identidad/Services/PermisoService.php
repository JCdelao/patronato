<?php

namespace Patronato\Modules\Identidad\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Patronato\Modules\Identidad\Models\Rol;

class PermisoService
{
    /**
     * Módulos y acciones que se pueden permitir (HU-07).
     */
    public const MODULOS = [
        'padron' => ['etiqueta' => 'Viviendas y residentes', 'acciones' => ['ver', 'registrar', 'anular']],
        'cobros' => ['etiqueta' => 'Cobros', 'acciones' => ['ver', 'registrar', 'verificar', 'aprobar', 'anular']],
        'vigilancia' => ['etiqueta' => 'Vigilancia', 'acciones' => ['ver', 'registrar']],
        'vehiculos' => ['etiqueta' => 'Vehículos', 'acciones' => ['ver', 'registrar', 'anular']],
        'reportes' => ['etiqueta' => 'Reportes', 'acciones' => ['ver']],
        'auditoria' => ['etiqueta' => 'Auditoría', 'acciones' => ['ver']],
    ];

    /**
     * Propuesta inicial de cada residencial; la decisión final es de la Junta Directiva.
     */
    public const PREDETERMINADOS = [
        Rol::PRESIDENTE => ['padron.ver', 'padron.registrar', 'padron.anular', 'auditoria.ver'],
        Rol::SECRETARIO => ['padron.ver', 'padron.registrar', 'padron.anular'],
        'tesorero' => ['padron.ver'],
        'fiscal' => ['padron.ver', 'auditoria.ver'],
        'vocal' => ['padron.ver'],
    ];

    public function __construct(private AuditoriaService $auditoria) {}

    /**
     * @return array<int, string>
     */
    public function catalogo(): array
    {
        $permisos = [];

        foreach (self::MODULOS as $modulo => $definicion) {
            foreach ($definicion['acciones'] as $accion) {
                $permisos[] = "{$modulo}.{$accion}";
            }
        }

        return $permisos;
    }

    public function puede(User $usuario, string $modulo, string $accion): bool
    {
        if ($usuario->residencial_id === null) {
            return false;
        }

        // El administrador técnico configura el residencial, así que no depende de la matriz.
        if ($usuario->rol?->codigo === Rol::ADMIN_TECNICO) {
            return in_array("{$modulo}.{$accion}", $this->catalogo(), true);
        }

        return $usuario->rol_id !== null && DB::table('rol_permisos')
            ->where('residencial_id', $usuario->residencial_id)
            ->where('rol_id', $usuario->rol_id)
            ->where('modulo', $modulo)
            ->where('accion', $accion)
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    public function deUsuario(User $usuario): array
    {
        if ($usuario->residencial_id === null || $usuario->rol_id === null) {
            return [];
        }

        return $usuario->rol?->codigo === Rol::ADMIN_TECNICO
            ? $this->catalogo()
            : $this->deRol($usuario->residencial_id, $usuario->rol_id);
    }

    /**
     * @return array<int, string>
     */
    public function deRol(int $residencialId, int $rolId): array
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

    public function asignarPredeterminados(int $residencialId): void
    {
        $roles = Rol::query()->whereIn('codigo', array_keys(self::PREDETERMINADOS))->pluck('id', 'codigo');

        foreach (self::PREDETERMINADOS as $codigo => $permisos) {
            if (! $roles->has($codigo)) {
                continue;
            }

            DB::table('rol_permisos')->insertOrIgnore(
                $this->filas($residencialId, (int) $roles[$codigo], $permisos),
            );
        }
    }

    /**
     * Reemplaza los permisos de un rol y deja constancia del cambio (HU-07).
     *
     * @param  array<int, string>  $permisos
     */
    public function actualizar(User $actor, Rol $rol, array $permisos): void
    {
        $residencialId = (int) $actor->residencial_id;
        $anteriores = $this->deRol($residencialId, $rol->id);

        $nuevos = array_values(array_unique($permisos));
        sort($nuevos);

        if ($anteriores === $nuevos) {
            return;
        }

        DB::transaction(function () use ($actor, $rol, $residencialId, $anteriores, $nuevos) {
            DB::table('rol_permisos')
                ->where('residencial_id', $residencialId)
                ->where('rol_id', $rol->id)
                ->delete();

            DB::table('rol_permisos')->insert($this->filas($residencialId, $rol->id, $nuevos));

            $this->auditoria->registrar($actor, 'identidad', 'permisos_actualizados', $rol, [
                'valor_anterior' => ['permisos' => $anteriores],
                'valor_nuevo' => ['permisos' => $nuevos],
                'motivo' => "Permisos del rol {$rol->nombre}",
            ]);
        });
    }

    /**
     * @param  array<int, string>  $permisos
     * @return array<int, array{residencial_id: int, rol_id: int, modulo: string, accion: string}>
     */
    private function filas(int $residencialId, int $rolId, array $permisos): array
    {
        return array_map(function (string $permiso) use ($residencialId, $rolId): array {
            [$modulo, $accion] = explode('.', $permiso);

            return ['residencial_id' => $residencialId, 'rol_id' => $rolId, 'modulo' => $modulo, 'accion' => $accion];
        }, $permisos);
    }
}
