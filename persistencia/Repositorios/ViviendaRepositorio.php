<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Patronato\Persistencia\Models\Vivienda;

class ViviendaRepositorio extends Repositorio
{
    /**
     * Viviendas activas o inactivas del padrón, con sus residentes
     *
     * Busca por código, nombre o teléfono de un residente, o por la placa o el número de comprobante de un vehículo activo.
     *
     * @return LengthAwarePaginator<int, Vivienda>
     */
    public function paginarPadron(bool $activas, string $buscar, int $porPagina): LengthAwarePaginator
    {
        return Vivienda::query()
            ->where('activa', $activas)
            ->when($buscar !== '', function (Builder $query) use ($buscar) {
                $patron = Busqueda::patron($buscar);
                $placa = Busqueda::patronPlaca($buscar);

                $query->where(function (Builder $query) use ($patron, $placa) {
                    $query->whereRaw(Busqueda::columna('codigo').' like ?', [$patron])
                        ->orWhereHas('vinculosVigentes.residente', function (Builder $query) use ($patron) {
                            $query->whereRaw(Busqueda::columna("nombres || ' ' || apellidos").' like ?', [$patron])
                                ->orWhereRaw(Busqueda::columna('telefono').' like ?', [$patron]);
                        })
                        // La placa o el comprobante de un vehículo activo también encuentra su vivienda, con o sin separadores
                        ->when($placa, fn (Builder $query, string $placa) => $query->orWhereHas('vehiculos', fn (Builder $query) => $query->where('activo', true)->where(fn (Builder $query) => $query->where('placa', 'like', $placa)->orWhere('comprobante_numero', 'like', $placa))));
                });
            })
            ->with(['tipoVivienda:id,nombre', 'padre:id,codigo', 'conceptos:id,nombre', 'vinculosVigentes.residente.vinculosVigentes.vivienda'])
            ->orderBy('codigo')
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * Viviendas activas de un residencial, con su tipo
     *
     * @return Collection<int, Vivienda>
     */
    public function activasDe(int $residencialId): Collection
    {
        return Vivienda::query()
            ->where('residencial_id', $residencialId)
            ->where('activa', true)
            ->orderBy('codigo')
            ->get(['id', 'residencial_id', 'codigo', 'tipo_vivienda_id']);
    }

    public function buscar(int $id): ?Vivienda
    {
        return Vivienda::query()->find($id);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function bloquearActivas(int $residencial, array $ids): array
    {
        return array_values(array_map('intval', Vivienda::query()->where('residencial_id', $residencial)->whereKey($ids)
            ->where('activa', true)->orderBy('id')->lockForUpdate()->pluck('id')->all()));
    }

    /** @return Collection<int, Vivienda> */
    public function paraVisitas(int $residencialId): Collection
    {
        return Vivienda::query()->where('residencial_id', $residencialId)
            ->orderBy('codigo')->get(['id', 'codigo']);
    }

    /**
     * Vuelve a leer el tipo y la vivienda a la que pertenece
     */
    public function cargarTipoYPadre(Vivienda $vivienda): void
    {
        $vivienda->load(['tipoVivienda:id,nombre', 'padre:id,codigo']);
    }

    /**
     * Ids de los conceptos que paga la vivienda aparte de los que se cobran a todas
     *
     * @return list<int>
     */
    public function conceptosDe(Vivienda $vivienda): array
    {
        return array_values($vivienda->conceptos()->orderBy('nombre')->pluck('conceptos_cobro.id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * Nombres de los conceptos que paga la vivienda aparte de los que se cobran a todas
     *
     * @return list<string>
     */
    public function nombresDeConceptos(Vivienda $vivienda): array
    {
        return array_values($vivienda->conceptos()->orderBy('nombre')->pluck('nombre')->map(fn ($nombre): string => (string) $nombre)->all());
    }

    /**
     * Reemplaza los conceptos que paga la vivienda aparte de los que se cobran a todas
     *
     * @param  list<int>  $conceptoIds
     */
    public function asignarConceptos(Vivienda $vivienda, array $conceptoIds): void
    {
        $vivienda->conceptos()->sync(array_fill_keys($conceptoIds, [
            'residencial_id' => $vivienda->residencial_id,
            'created_at' => now(),
        ]));
    }

    public function tieneHijas(Vivienda $vivienda): bool
    {
        return $vivienda->hijas()->exists();
    }

    /**
     * Id, código y vivienda a la que pertenecen las viviendas activas
     *
     * @return Collection<int, Vivienda>
     */
    public function activas(): Collection
    {
        return Vivienda::query()
            ->where('activa', true)
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'vivienda_padre_id']);
    }

    /**
     * Viviendas activas con su responsable de pago
     *
     * Con un id devuelve solo esa vivienda; si no, busca por código o nombre del residente.
     *
     * @return Collection<int, Vivienda>
     */
    public function activasConResponsable(string $buscar, ?int $viviendaId, int $limite): Collection
    {
        return $this->conResponsable($buscar, $viviendaId, $limite, soloActivas: true);
    }

    /**
     * Viviendas activas e inactivas con su responsable de pago, las activas primero
     *
     * Sirve para consultar el historial de una vivienda que ya se dio de baja.
     *
     * @return Collection<int, Vivienda>
     */
    public function todasConResponsable(string $buscar, ?int $viviendaId, int $limite): Collection
    {
        return $this->conResponsable($buscar, $viviendaId, $limite, soloActivas: false);
    }

    /**
     * @return Collection<int, Vivienda>
     */
    private function conResponsable(string $buscar, ?int $viviendaId, int $limite, bool $soloActivas): Collection
    {
        return Vivienda::query()
            ->when($soloActivas, fn (Builder $query) => $query->where('activa', true), fn (Builder $query) => $query->orderByDesc('activa'))
            ->when($viviendaId !== null, fn (Builder $query) => $query->whereKey($viviendaId))
            ->when($viviendaId === null && $buscar !== '', function (Builder $query) use ($buscar) {
                $patron = Busqueda::patron($buscar);

                $query->where(function (Builder $query) use ($patron) {
                    $query->whereRaw(Busqueda::columna('codigo').' like ?', [$patron])
                        ->orWhereHas('vinculosVigentes.residente', function (Builder $query) use ($patron) {
                            $query->whereRaw(Busqueda::columna("nombres || ' ' || apellidos").' like ?', [$patron]);
                        });
                });
            })
            ->with(['vinculosVigentes' => fn ($query) => $query->where('es_responsable_pago', true)->with('residente')])
            ->orderBy('codigo')
            ->limit($limite)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Vivienda
    {
        return Vivienda::query()->create($datos);
    }

    public function tieneResidentes(Vivienda $vivienda): bool
    {
        return $vivienda->vinculosVigentes()->exists();
    }

    /**
     * Deja inactiva una vivienda que no se pudo borrar
     */
    public function inactivar(Vivienda $vivienda): void
    {
        // Tras un borrado fallido el modelo cree que ya no existe
        $vivienda->exists = true;

        $vivienda->update(['activa' => false]);
    }
}
