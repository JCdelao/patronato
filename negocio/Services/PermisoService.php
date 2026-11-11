<?php

namespace Patronato\Negocio\Services;

use Patronato\Negocio\Permisos\AccesoTotal;
use Patronato\Negocio\Permisos\EstrategiaPermisos;
use Patronato\Negocio\Permisos\PermisosPorRol;
use Patronato\Negocio\Permisos\SinPermisos;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\RolPermisoRepositorio;
use Patronato\Persistencia\Repositorios\RolRepositorio;
use Patronato\Persistencia\Transaccion;

class PermisoService
{
    /**
     * Módulos y acciones que se pueden permitir
     */
    public const MODULOS = [
        'padron' => ['etiqueta' => 'Viviendas y residentes', 'acciones' => ['ver', 'registrar', 'anular']],
        'cobros' => ['etiqueta' => 'Cobros', 'acciones' => ['ver', 'registrar', 'verificar', 'aprobar', 'anular']],
        'parametros' => ['etiqueta' => 'Parámetros de cobro', 'acciones' => ['ver', 'registrar']],
        'vigilancia' => ['etiqueta' => 'Vigilancia', 'acciones' => ['ver', 'registrar']],
        'vehiculos' => ['etiqueta' => 'Vehículos', 'acciones' => ['ver', 'registrar', 'anular']],
        'reportes' => ['etiqueta' => 'Reportes', 'acciones' => ['ver']],
        'auditoria' => ['etiqueta' => 'Auditoría', 'acciones' => ['ver']],
    ];

    /**
     * Permisos iniciales de cada residencial
     *
     * Solo se asignan al crear un residencial. En los que ya existen,
     * el administrador técnico los activa desde la pantalla de permisos.
     */
    public const PREDETERMINADOS = [
        Rol::PRESIDENTE => ['padron.ver', 'padron.registrar', 'padron.anular', 'auditoria.ver', 'parametros.ver'],
        Rol::SECRETARIO => ['padron.ver', 'padron.registrar', 'padron.anular'],
        'tesorero' => ['padron.ver', 'parametros.ver', 'parametros.registrar'],
        'fiscal' => ['padron.ver', 'auditoria.ver', 'parametros.ver'],
        'vocal' => ['padron.ver'],
        'cobrador' => ['cobros.registrar'],
    ];

    public function __construct(
        private RolRepositorio $roles,
        private RolPermisoRepositorio $matriz,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

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
            default => new PermisosPorRol($this->matriz),
        };
    }

    /**
     * Indica si el rol del usuario permite una acción en un módulo
     */
    public function puede(User $usuario, string $modulo, string $accion): bool
    {
        return $this->estrategia($usuario)->puede($usuario, $modulo, $accion);
    }

    /**
     * Ruta de inicio según el rol
     */
    public function rutaInicio(User $usuario): string
    {
        return match (true) {
            $usuario->esAdminPlataforma() => 'plataforma.residenciales.index',
            $this->puede($usuario, 'padron', 'ver') => 'padron.viviendas.index',
            default => 'dashboard',
        };
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
        return $this->matriz->deRol($residencialId, $rolId);
    }

    public function asignarPredeterminados(int $residencialId): void
    {
        $roles = $this->roles->idsPorCodigo(array_keys(self::PREDETERMINADOS));

        foreach (self::PREDETERMINADOS as $codigo => $permisos) {
            if (! isset($roles[$codigo])) {
                continue;
            }

            $this->matriz->agregar($this->filas($residencialId, $roles[$codigo], $permisos));
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

        $this->transaccion->ejecutar(function () use ($actor, $rol, $residencialId, $anteriores, $nuevos) {
            $this->matriz->reemplazar($residencialId, $rol->id, $this->filas($residencialId, $rol->id, $nuevos));

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
