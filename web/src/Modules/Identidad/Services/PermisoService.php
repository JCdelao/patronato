<?php

namespace Patronato\Modules\Identidad\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Patronato\Modules\Identidad\Models\Rol;
use Patronato\Modules\Identidad\Permisos\AccesoTotal;
use Patronato\Modules\Identidad\Permisos\EstrategiaPermisos;
use Patronato\Modules\Identidad\Permisos\PermisosPorRol;
use Patronato\Modules\Identidad\Permisos\SinPermisos;

class PermisoService
{
    /**
     * Módulos y acciones que se pueden permitir
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
     * Permisos iniciales de cada residencial
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

    /**
     * Elige la estrategia de permisos según el usuario
     */
    public function estrategia(User $usuario): EstrategiaPermisos
    {
        return match (true) {
            $usuario->residencial_id === null || $usuario->rol_id === null => new SinPermisos,
            $usuario->rol?->codigo === Rol::ADMIN_TECNICO => new AccesoTotal($this->catalogo()),
            default => new PermisosPorRol,
        };
    }

    public function puede(User $usuario, string $modulo, string $accion): bool
    {
        return $this->estrategia($usuario)->puede($usuario, $modulo, $accion);
    }

    /**
     * @return array<int, string>
     */
    public function deUsuario(User $usuario): array
    {
        return $this->estrategia($usuario)->permisos($usuario);
    }

    /**
     * @return array<int, string>
     */
    public function deRol(int $residencialId, int $rolId): array
    {
        return PermisosPorRol::deRol($residencialId, $rolId);
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
     * Reemplaza los permisos de un rol
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
