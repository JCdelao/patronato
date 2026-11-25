<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Entradas, salidas e incidencias del residencial en una sola lista, de la más reciente a la más antigua
 *
 * El turno de cada evento es el que el guardia tenía programado a esa hora.
 *
 * @phpstan-type FiltrosBitacora array{desde: string, hasta: string, tipo: string|null, turno: string|null, guardia_id: int|null, vivienda_id: int|null, vivienda_codigo: string|null, medio_entrada_id: int|null}
 * @phpstan-type EventoBitacora array{tipo: string, id: int, fecha: string, guardia: string, turno: string|null, vivienda: string|null, medio: string|null, visitante: string|null, placa: string|null, categoria: string|null, detalle: string|null, urgente: bool}
 */
class BitacoraAccesosRepositorio
{
    public const ENTRADA = 'entrada';

    public const SALIDA = 'salida';

    public const INCIDENCIA = 'incidencia';

    /**
     * Página de eventos con los filtros
     *
     * Las fechas de los filtros son UTC; desde se incluye y hasta no.
     *
     * @param  FiltrosBitacora  $filtros
     * @return LengthAwarePaginator<int, EventoBitacora>
     */
    public function paginar(int $residencialId, array $filtros, int $porPagina): LengthAwarePaginator
    {
        $consulta = $this->eventos($residencialId, $filtros);

        if ($consulta === null) {
            return new LengthAwarePaginator([], 0, $porPagina);
        }

        return $this->ordenar($consulta)
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (stdClass $fila): array => $this->fila($fila));
    }

    /**
     * Eventos con los filtros, hasta el límite
     *
     * @param  FiltrosBitacora  $filtros
     * @return list<EventoBitacora>
     */
    public function todos(int $residencialId, array $filtros, int $limite): array
    {
        $consulta = $this->eventos($residencialId, $filtros);

        if ($consulta === null) {
            return [];
        }

        return array_values($this->ordenar($consulta)->limit($limite)->get()
            ->map(fn (stdClass $fila): array => $this->fila($fila))
            ->all());
    }

    /**
     * Cantidad de eventos de cada tipo con los filtros
     *
     * @param  FiltrosBitacora  $filtros
     * @return array{entrada: int, salida: int, incidencia: int}
     */
    public function totales(int $residencialId, array $filtros): array
    {
        $consulta = $this->eventos($residencialId, $filtros);

        $filas = $consulta === null ? collect() : DB::query()->fromSub($consulta, 'bitacora')
            ->selectRaw('tipo, count(*) as total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        $cantidad = fn (string $tipo): int => (int) ($filas[$tipo] ?? 0);

        return [
            self::ENTRADA => $cantidad(self::ENTRADA),
            self::SALIDA => $cantidad(self::SALIDA),
            self::INCIDENCIA => $cantidad(self::INCIDENCIA),
        ];
    }

    /**
     * Guardias, viviendas y medios de entrada del residencial, incluidos los inactivos
     *
     * @return array{guardias: list<array{id: int, nombre: string}>, viviendas: list<array{id: int, nombre: string}>, medios: list<array{id: int, nombre: string}>}
     */
    public function opciones(int $residencialId): array
    {
        $lista = fn (string $tabla, string $columna): array => array_values(DB::table($tabla)
            ->where('residencial_id', $residencialId)
            ->orderBy($columna)
            ->get(['id', $columna])
            ->map(fn (stdClass $fila): array => ['id' => (int) $fila->id, 'nombre' => (string) $fila->{$columna}])
            ->all());

        return [
            'guardias' => $lista('guardias', 'nombre'),
            'viviendas' => $lista('viviendas', 'codigo'),
            'medios' => $lista('medios_entrada', 'nombre'),
        ];
    }

    /**
     * Une las consultas que aplican; null si los filtros no dejan ninguna
     *
     * Las incidencias no tienen medio de entrada: con ese filtro no aparecen.
     *
     * @param  FiltrosBitacora  $filtros
     */
    private function eventos(int $residencialId, array $filtros): ?Builder
    {
        $partes = [];

        foreach ([self::ENTRADA, self::SALIDA] as $tipo) {
            if ($filtros['tipo'] === null || $filtros['tipo'] === $tipo) {
                $partes[] = $this->visitas($residencialId, $filtros, $tipo);
            }
        }

        if ($filtros['medio_entrada_id'] === null && ($filtros['tipo'] === null || $filtros['tipo'] === self::INCIDENCIA)) {
            $partes[] = $this->incidencias($residencialId, $filtros);
        }

        $consulta = array_shift($partes);

        foreach ($partes as $parte) {
            $consulta?->unionAll($parte);
        }

        return $consulta;
    }

    /**
     * Entradas o salidas de visitas con el guardia que las registró
     *
     * @param  FiltrosBitacora  $filtros
     * @param  self::ENTRADA|self::SALIDA  $tipo
     */
    private function visitas(int $residencialId, array $filtros, string $tipo): Builder
    {
        $entrada = $tipo === self::ENTRADA;
        $fecha = $entrada ? 'v.entrada_en' : 'v.salida_en';
        $guardia = $entrada ? 'v.guardia_entrada_id' : 'v.guardia_salida_id';
        $turno = $this->turno($guardia, $fecha);

        return DB::table('visitas as v')
            ->join('guardias as g', 'g.id', '=', $guardia)
            ->join('viviendas as vi', 'vi.id', '=', 'v.vivienda_id')
            ->join('medios_entrada as m', 'm.id', '=', 'v.medio_entrada_id')
            ->where('v.residencial_id', $residencialId)
            ->where($fecha, '>=', $filtros['desde'])
            ->where($fecha, '<', $filtros['hasta'])
            ->when($filtros['guardia_id'], fn (Builder $query, int $id) => $query->where($guardia, $id))
            ->when($filtros['turno'], fn (Builder $query, string $valor) => $query->whereRaw("{$turno} = ?", [$valor]))
            ->when($filtros['vivienda_id'], fn (Builder $query, int $id) => $query->where('v.vivienda_id', $id))
            ->when($filtros['medio_entrada_id'], fn (Builder $query, int $id) => $query->where('v.medio_entrada_id', $id))
            // Los nulos llevan tipo para que la unión funcione en PostgreSQL
            ->selectRaw("'{$tipo}' as tipo, v.id as id, {$fecha} as fecha, g.nombre as guardia, {$turno} as turno,
                vi.codigo as vivienda, m.nombre as medio, v.nombre_visitante as visitante, v.placa as placa,
                cast(null as varchar(80)) as categoria, v.motivo as detalle, 0 as urgente");
    }

    /**
     * Incidencias con su tipo y el guardia que las reportó
     *
     * La vivienda se escribe en la descripción: el filtro busca su código en el texto.
     *
     * @param  FiltrosBitacora  $filtros
     */
    private function incidencias(int $residencialId, array $filtros): Builder
    {
        $turno = $this->turno('i.guardia_id', 'i.ocurrida_en');

        return DB::table('incidencias as i')
            ->join('guardias as g', 'g.id', '=', 'i.guardia_id')
            ->join('tipos_incidencia as ti', 'ti.id', '=', 'i.tipo_incidencia_id')
            ->where('i.residencial_id', $residencialId)
            ->where('i.ocurrida_en', '>=', $filtros['desde'])
            ->where('i.ocurrida_en', '<', $filtros['hasta'])
            ->when($filtros['guardia_id'], fn (Builder $query, int $id) => $query->where('i.guardia_id', $id))
            ->when($filtros['turno'], fn (Builder $query, string $valor) => $query->whereRaw("{$turno} = ?", [$valor]))
            ->when($filtros['vivienda_codigo'], fn (Builder $query, string $codigo) => $query
                ->whereRaw(Busqueda::columna('i.descripcion').' like ?', [Busqueda::patron($codigo)]))
            ->selectRaw("'".self::INCIDENCIA."' as tipo, i.id as id, i.ocurrida_en as fecha, g.nombre as guardia, {$turno} as turno,
                cast(null as varchar(40)) as vivienda, cast(null as varchar(80)) as medio, cast(null as varchar(150)) as visitante,
                cast(null as varchar(20)) as placa, ti.nombre as categoria, i.descripcion as detalle,
                case when i.urgente then 1 else 0 end as urgente");
    }

    /**
     * Tipo del turno que el guardia tenía programado a la hora del evento
     *
     * @param  literal-string  $guardia
     * @param  literal-string  $fecha
     * @return literal-string
     */
    private function turno(string $guardia, string $fecha): string
    {
        return "(select t.tipo from turnos t where t.guardia_id = {$guardia}
            and t.inicio <= {$fecha} and t.fin > {$fecha} order by t.inicio desc limit 1)";
    }

    private function ordenar(Builder $consulta): Builder
    {
        return DB::query()->fromSub($consulta, 'bitacora')
            ->orderByDesc('fecha')
            ->orderBy('tipo')
            ->orderByDesc('id');
    }

    /**
     * @return EventoBitacora
     */
    private function fila(stdClass $fila): array
    {
        $texto = fn (mixed $valor): ?string => $valor === null ? null : (string) $valor;

        return [
            'tipo' => (string) $fila->tipo,
            'id' => (int) $fila->id,
            'fecha' => (string) $fila->fecha,
            'guardia' => (string) $fila->guardia,
            'turno' => $texto($fila->turno),
            'vivienda' => $texto($fila->vivienda),
            'medio' => $texto($fila->medio),
            'visitante' => $texto($fila->visitante),
            'placa' => $texto($fila->placa),
            'categoria' => $texto($fila->categoria),
            'detalle' => $texto($fila->detalle),
            'urgente' => (bool) $fila->urgente,
        ];
    }
}
