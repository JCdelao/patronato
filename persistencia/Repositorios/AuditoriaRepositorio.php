<?php

namespace Patronato\Persistencia\Repositorios;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Patronato\Persistencia\Models\Auditoria;
use Patronato\Persistencia\Models\User;

class AuditoriaRepositorio
{
    /**
     * Guarda un registro de la bitácora sobre un modelo
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
     * Registro más reciente de una acción para cada entidad
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

    /**
     * Registros de varias entidades, del más reciente al más antiguo
     *
     * @param  array<string, list<int>>  $entidades
     * @return Collection<int, Auditoria>
     */
    public function historial(int $residencialId, array $entidades): Collection
    {
        return Auditoria::query()
            ->with('usuario:id,name')
            ->where('residencial_id', $residencialId)
            ->where(function (Builder $query) use ($entidades) {
                foreach ($entidades as $entidad => $ids) {
                    $query->orWhere(fn (Builder $query) => $query->where('entidad', $entidad)->whereIn('entidad_id', $ids));
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Registros del residencial con filtros, del más reciente al más antiguo
     *
     * @param  array{desde: CarbonInterface|null, hasta: CarbonInterface|null, user_id: int|null, modulo: string|null, accion: string|null}  $filtros
     * @return LengthAwarePaginator<int, Auditoria>
     */
    public function paginar(int $residencialId, array $filtros, int $porPagina): LengthAwarePaginator
    {
        return $this->delResidencial($residencialId)
            ->with('usuario:id,name')
            ->when($filtros['desde'], fn (Builder $query, CarbonInterface $fecha) => $query->where('created_at', '>=', $fecha))
            ->when($filtros['hasta'], fn (Builder $query, CarbonInterface $fecha) => $query->where('created_at', '<=', $fecha))
            ->when($filtros['user_id'], fn (Builder $query, int $id) => $query->where('user_id', $id))
            ->when($filtros['modulo'], fn (Builder $query, string $valor) => $query->where('modulo', $valor))
            ->when($filtros['accion'], fn (Builder $query, string $valor) => $query->where('accion', $valor))
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * Valores distintos de una columna de la bitácora del residencial
     *
     * @param  'modulo'|'accion'  $columna
     * @return list<string>
     */
    public function valoresDe(int $residencialId, string $columna): array
    {
        /** @var list<string> $valores */
        $valores = $this->delResidencial($residencialId)->distinct()->orderBy($columna)->pluck($columna)->all();

        return $valores;
    }

    /**
     * Nombre de cada registro de una tabla
     *
     * @param  literal-string  $columna
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    public function nombres(string $tabla, string $columna, array $ids): array
    {
        return DB::table($tabla)
            ->whereIn('id', array_unique($ids))
            ->selectRaw('id, '.$columna.' as nombre')
            ->pluck('nombre', 'id')
            ->map(fn ($nombre) => (string) $nombre)
            ->all();
    }

    /**
     * @return Builder<Auditoria>
     */
    private function delResidencial(int $residencialId): Builder
    {
        return Auditoria::query()->where('residencial_id', $residencialId);
    }
}
