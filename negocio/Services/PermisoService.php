<?php

namespace Patronato\Negocio\Services;

use Illuminate\Validation\ValidationException;
use Patronato\Negocio\Permisos\AccesoTotal;
use Patronato\Negocio\Permisos\EstrategiaPermisos;
use Patronato\Negocio\Permisos\PermisosPorRol;
use Patronato\Negocio\Permisos\SinPermisos;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\ResidenteRepositorio;
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
        'cajas' => ['etiqueta' => 'Cierre de caja', 'acciones' => ['registrar']],
        // Correcciones al saldo de una vivienda; solo el presidente y el tesorero (ver RESERVADOS)
        'ajustes' => ['etiqueta' => 'Ajustes de saldo', 'acciones' => ['aprobar']],
        // Separado de cobros.registrar: registrar transferencias no permite cobrar en efectivo.
        // 'verificar' confirma o rechaza las transferencias pendientes (HU-26).
        'transferencias' => ['etiqueta' => 'Transferencias', 'acciones' => ['registrar', 'verificar']],
        'parametros' => ['etiqueta' => 'Parámetros de cobro', 'acciones' => ['ver', 'registrar']],
        'mora' => ['etiqueta' => 'Regla de mora', 'acciones' => ['registrar']],
        'tesoreria' => ['etiqueta' => 'Ingresos y egresos', 'acciones' => ['ver', 'registrar', 'anular']],
        'guardias' => ['etiqueta' => 'Guardias', 'acciones' => ['ver', 'registrar']],
        'turnos' => ['etiqueta' => 'Programación de turnos', 'acciones' => ['ver', 'registrar']],
        'tipos_incidencia' => ['etiqueta' => 'Tipos de incidencia', 'acciones' => ['registrar']],
        // Para el guardia: además del permiso, exigen una cuenta vinculada a un guardia activo
        'entrega_turnos' => ['etiqueta' => 'Iniciar y entregar sus turnos', 'acciones' => ['registrar']],
        'incidencias' => ['etiqueta' => 'Incidencias', 'acciones' => ['ver', 'registrar']],
        'vehiculos' => ['etiqueta' => 'Vehículos', 'acciones' => ['ver', 'registrar', 'anular']],
        'carnets' => ['etiqueta' => 'Carnets', 'acciones' => ['ver', 'registrar', 'anular']],
        'reportes' => ['etiqueta' => 'Reportes', 'acciones' => ['ver']],
        'auditoria' => ['etiqueta' => 'Auditoría', 'acciones' => ['ver']],
        // Además del permiso, el portal exige una cuenta vinculada a un residente activo
        'portal' => ['etiqueta' => 'Portal del residente', 'acciones' => ['ver', 'registrar']],
    ];

    /**
     * Permisos iniciales de cada residencial
     *
     * Solo se asignan al crear un residencial. En los que ya existen,
     * el administrador técnico los activa desde la pantalla de permisos.
     */
    public const PREDETERMINADOS = [
        Rol::PRESIDENTE => ['padron.ver', 'padron.registrar', 'padron.anular', 'auditoria.ver', 'parametros.ver', 'reportes.ver', 'cobros.aprobar', 'ajustes.aprobar', 'mora.registrar', 'cajas.registrar', 'tesoreria.ver', 'transferencias.verificar', 'vehiculos.ver', 'carnets.ver', 'guardias.ver', 'guardias.registrar', 'turnos.ver', 'turnos.registrar', 'tipos_incidencia.registrar', 'incidencias.ver'],
        Rol::SECRETARIO => ['padron.ver', 'padron.registrar', 'padron.anular', 'vehiculos.ver', 'vehiculos.registrar', 'vehiculos.anular', 'carnets.ver', 'carnets.registrar', 'carnets.anular'],
        'tesorero' => ['padron.ver', 'parametros.ver', 'parametros.registrar', 'cobros.ver', 'cobros.anular', 'ajustes.aprobar', 'cajas.registrar', 'transferencias.registrar', 'transferencias.verificar', 'reportes.ver', 'mora.registrar', 'tesoreria.ver', 'tesoreria.registrar', 'tesoreria.anular'],
        'fiscal' => ['padron.ver', 'auditoria.ver', 'parametros.ver', 'reportes.ver', 'cobros.aprobar', 'tesoreria.ver'],
        'vocal' => ['padron.ver'],
        'cobrador' => ['cobros.registrar'],
        Rol::GUARDIA => ['vehiculos.ver', 'carnets.ver', 'entrega_turnos.registrar', 'incidencias.registrar'],
        Rol::RESIDENTE => ['portal.ver', 'portal.registrar'],
    ];

    /**
     * Permisos que solo pueden tener ciertos roles, y que esos roles conservan siempre
     *
     * No se asignan a otro rol desde la pantalla de permisos, no se quitan a los roles
     * indicados y el administrador técnico no los recibe con su acceso total.
     */
    public const RESERVADOS = [
        'ajustes.aprobar' => [Rol::PRESIDENTE, 'tesorero'],
    ];

    public function __construct(
        private RolRepositorio $roles,
        private RolPermisoRepositorio $matriz,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
        private ResidenteRepositorio $residentes,
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
     * Catálogo sin los permisos reservados a ciertos roles: es lo que recibe el administrador técnico
     *
     * @return array<int, string>
     */
    public function catalogoSinReservados(): array
    {
        return array_values(array_diff($this->catalogo(), array_keys(self::RESERVADOS)));
    }

    /**
     * Indica si un rol puede tener el permiso; los no reservados los puede tener cualquiera
     */
    public function rolPuedeTener(?string $codigoRol, string $permiso): bool
    {
        return ! isset(self::RESERVADOS[$permiso]) || in_array($codigoRol, self::RESERVADOS[$permiso], true);
    }

    /**
     * Permisos reservados que el rol no puede tener
     *
     * @return list<string>
     */
    public function noAsignables(Rol $rol): array
    {
        $bloqueados = [];

        foreach (array_keys(self::RESERVADOS) as $permiso) {
            if (! $this->rolPuedeTener($rol->codigo, $permiso)) {
                $bloqueados[] = $permiso;
            }
        }

        return $bloqueados;
    }

    /**
     * Permisos reservados que el rol debe conservar siempre
     *
     * @return list<string>
     */
    public function obligatorios(Rol $rol): array
    {
        $obligatorios = [];

        foreach (self::RESERVADOS as $permiso => $roles) {
            if (in_array($rol->codigo, $roles, true)) {
                $obligatorios[] = $permiso;
            }
        }

        return $obligatorios;
    }

    // Patrón Factory Method (creacional): este método decide qué estrategia de permisos crear
    /**
     * Elige la estrategia de permisos según el usuario
     */
    public function estrategia(User $usuario): EstrategiaPermisos
    {
        return match (true) {
            $usuario->residencial_id === null || $usuario->rol_id === null => new SinPermisos,
            $usuario->rol?->codigo === Rol::ADMIN_TECNICO => new AccesoTotal($this->catalogoSinReservados()),
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
            // Un residente desactivado no entra al portal: va al panel
            $this->puede($usuario, 'portal', 'ver') && $this->residentes->activoDeUsuario($usuario->id) !== null => 'portal.transferencias.index',
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
     * Rechaza un permiso reservado para un rol que no puede tenerlo, y conserva los
     * permisos reservados que el rol debe tener aunque la petición no los incluya.
     *
     * @param  array<int, string>  $permisos
     *
     * @throws ValidationException
     */
    public function actualizar(User $actor, Rol $rol, array $permisos): void
    {
        $residencialId = (int) $actor->residencial_id;
        $anteriores = $this->deRol($residencialId, $rol->id);

        foreach ($permisos as $permiso) {
            if (! $this->rolPuedeTener($rol->codigo, $permiso)) {
                throw ValidationException::withMessages(['permisos' => "El permiso {$permiso} solo puede tenerlo el presidente o el tesorero."]);
            }
        }

        // Un permiso obligatorio del rol no se puede quitar
        $nuevos = array_values(array_unique([...$permisos, ...$this->obligatorios($rol)]));
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
