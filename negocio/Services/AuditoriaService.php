<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\AuditoriaRepositorio;

class AuditoriaService
{
    /**
     * Zona horaria de los filtros de fecha
     */
    private const ZONA_LOCAL = 'America/Tegucigalpa';

    public function __construct(private AuditoriaRepositorio $registros) {}

    /**
     * Agrega un registro a la bitácora de auditoría
     *
     * @param  array{residencial_id?: int|null, valor_anterior?: array<string, mixed>, valor_nuevo?: array<string, mixed>, motivo?: string}  $datos
     */
    public function registrar(User $usuario, string $modulo, string $accion, Model $modelo, array $datos = []): void
    {
        $this->registros->registrar($usuario, $modulo, $accion, $modelo, $datos);
    }

    /**
     * Audita un intento de abrir un registro de otro residencial
     */
    public function registrarAccesoDenegado(User $usuario, Model $modelo): void
    {
        $this->registrar($usuario, 'seguridad', 'acceso_denegado', $modelo, [
            'motivo' => 'Intento de acceder a un registro de otro residencial',
        ]);
    }

    /**
     * Devuelve el registro más reciente de una acción para cada entidad
     *
     * @param  array<int, int>  $ids
     * @return array<int, Auditoria>
     */
    public function ultimosPorEntidad(string $entidad, array $ids, string $accion): array
    {
        return $this->registros->ultimosPorEntidad($entidad, $ids, $accion);
    }

    /**
     * Bitácora del residencial filtrada; las fechas son días completos de la hora local
     *
     * @param  array{desde: string|null, hasta: string|null, user_id: string|null, modulo: string|null, accion: string|null}  $filtros
     * @return LengthAwarePaginator<int, Auditoria>
     */
    public function buscar(int $residencialId, array $filtros): LengthAwarePaginator
    {
        return $this->registros->paginar($residencialId, [
            'desde' => $filtros['desde'] === null ? null : CarbonImmutable::parse($filtros['desde'], self::ZONA_LOCAL)->startOfDay()->utc(),
            'hasta' => $filtros['hasta'] === null ? null : CarbonImmutable::parse($filtros['hasta'], self::ZONA_LOCAL)->endOfDay()->utc(),
            'user_id' => $filtros['user_id'] === null ? null : (int) $filtros['user_id'],
            'modulo' => $filtros['modulo'],
            'accion' => $filtros['accion'],
        ], 25);
    }

    /**
     * Módulos que aparecen en la bitácora del residencial
     *
     * @return list<string>
     */
    public function modulos(int $residencialId): array
    {
        return $this->registros->valoresDe($residencialId, 'modulo');
    }

    /**
     * Acciones que aparecen en la bitácora del residencial
     *
     * @return list<string>
     */
    public function acciones(int $residencialId): array
    {
        return $this->registros->valoresDe($residencialId, 'accion');
    }
}
