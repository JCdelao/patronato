<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Patronato\Persistencia\Models\Sticker;
use Patronato\Persistencia\Models\Vehiculo;
use Patronato\Persistencia\Models\VehiculoConductor;

class VehiculoRepositorio extends Repositorio
{
    /**
     * Vehículos activos o dados de baja del residencial, ordenados por placa
     *
     * Busca por placa, número de comprobante, marca, modelo, color o código de la vivienda, sin importar mayúsculas ni tildes.
     *
     * @return LengthAwarePaginator<int, Vehiculo>
     */
    public function paginar(bool $activos, string $buscar, int $porPagina): LengthAwarePaginator
    {
        return Vehiculo::query()
            ->where('activo', $activos)
            ->when($buscar !== '', function (Builder $query) use ($buscar) {
                $patron = Busqueda::patron($buscar);
                $placa = Busqueda::patronPlaca($buscar);

                $query->where(function (Builder $query) use ($patron, $placa) {
                    $query->when($placa, fn (Builder $query, string $placa) => $query->orWhere('placa', 'like', $placa)->orWhere('comprobante_numero', 'like', $placa))
                        ->orWhereRaw(Busqueda::columna('marca').' like ?', [$patron])
                        ->orWhereRaw(Busqueda::columna('modelo').' like ?', [$patron])
                        ->orWhereRaw(Busqueda::columna('color').' like ?', [$patron])
                        ->orWhereHas('vivienda', fn (Builder $vivienda) => $vivienda->whereRaw(Busqueda::columna('codigo').' like ?', [$patron]));
                });
            })
            ->with(['vivienda:id,codigo', 'conductores.residente:id,nombres,apellidos', 'stickers'])
            // Los que no tienen placa todavía van al final, por número de comprobante
            ->orderByRaw('placa IS NULL')
            ->orderBy('placa')
            ->orderBy('comprobante_numero')
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * Vehículos activos de las viviendas, con su sticker vigente y sus conductores, ordenados por placa
     *
     * @param  list<int>  $viviendaIds
     * @return Collection<int, Vehiculo>
     */
    public function activosDeViviendas(array $viviendaIds): Collection
    {
        return Vehiculo::query()
            ->where('activo', true)
            ->whereIn('vivienda_id', $viviendaIds)
            ->with([
                'stickers' => fn (HasMany $query) => $query->whereIn('estado', Sticker::VIGENTES),
                'conductores.residente:id,nombres,apellidos',
            ])
            ->orderByRaw('placa IS NULL')
            ->orderBy('placa')
            ->orderBy('comprobante_numero')
            ->get();
    }

    /**
     * Indica si otro vehículo activo del residencial usa la placa, ya en su forma única
     */
    public function existePlacaActiva(string $placa, ?int $exceptoId = null): bool
    {
        return Vehiculo::query()
            ->where('activo', true)
            ->where('placa', $placa)
            ->when($exceptoId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->exists();
    }

    /**
     * Indica si otro vehículo activo del residencial usa el número de comprobante, ya en su forma única
     */
    public function existeComprobanteActivo(string $numero, ?int $exceptoId = null): bool
    {
        return Vehiculo::query()
            ->where('activo', true)
            ->where('comprobante_numero', $numero)
            ->when($exceptoId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Vehiculo
    {
        return Vehiculo::query()->create($datos);
    }

    /**
     * Vuelve a leer el vehículo reservándolo hasta terminar la transacción
     */
    public function bloquearRegistro(int $vehiculoId): ?Vehiculo
    {
        return Vehiculo::query()->lockForUpdate()->find($vehiculoId);
    }

    /**
     * Ids de los residentes autorizados a conducirlo
     *
     * @return list<int>
     */
    public function conductoresDe(Vehiculo $vehiculo): array
    {
        return array_values(array_map('intval', VehiculoConductor::query()
            ->where('vehiculo_id', $vehiculo->id)
            ->whereNotNull('residente_id')
            ->orderBy('residente_id')
            ->pluck('residente_id')
            ->all()));
    }

    /**
     * Deja como conductores exactamente a estos residentes
     *
     * @param  list<int>  $residenteIds
     */
    public function reemplazarConductores(Vehiculo $vehiculo, array $residenteIds): void
    {
        VehiculoConductor::query()->where('vehiculo_id', $vehiculo->id)->delete();

        foreach ($residenteIds as $residenteId) {
            VehiculoConductor::query()->create([
                'residencial_id' => $vehiculo->residencial_id,
                'vehiculo_id' => $vehiculo->id,
                'residente_id' => $residenteId,
            ]);
        }
    }
}
