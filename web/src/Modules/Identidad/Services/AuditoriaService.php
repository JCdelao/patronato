<?php

namespace Patronato\Modules\Identidad\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Patronato\Modules\Identidad\Models\Auditoria;

class AuditoriaService
{
    /**
     * Agrega un registro a la bitácora de auditoría
     *
     * @param  array{residencial_id?: int|null, valor_anterior?: array<string, mixed>, valor_nuevo?: array<string, mixed>, motivo?: string}  $datos
     */
    public function registrar(User $usuario, string $modulo, string $accion, Model $modelo, array $datos = []): void
    {
        $id = $modelo->getKey();

        Auditoria::query()->create([
            'residencial_id' => $usuario->residencial_id,
            'user_id' => $usuario->id,
            'modulo' => $modulo,
            'accion' => $accion,
            'entidad' => $modelo->getTable(),
            'entidad_id' => is_numeric($id) ? (int) $id : null,
            'ip_address' => request()->ip(),
            ...$datos,
        ]);
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
        return Auditoria::query()
            ->where('entidad', $entidad)
            ->where('accion', $accion)
            ->whereIn('entidad_id', $ids)
            ->orderBy('id')
            ->get()
            ->keyBy('entidad_id')
            ->all();
    }
}
