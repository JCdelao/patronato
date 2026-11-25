<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Patronato\Negocio\Reportes\BitacoraAccesos;
use Patronato\Negocio\Reportes\ExportadorBitacora;
use Patronato\Negocio\Reportes\ExportadorBitacoraExcel;
use Patronato\Negocio\Reportes\ExportadorBitacoraPdf;
use Patronato\Persistencia\Repositorios\BitacoraAccesosRepositorio;

/**
 * Bitácora de entradas, salidas e incidencias para supervisar la seguridad de la colonia
 *
 * @phpstan-import-type EventoBitacora from BitacoraAccesosRepositorio
 *
 * @phpstan-type Filtros array{desde: string, hasta: string, tipo: string|null, turno: string|null, guardia_id: int|null, vivienda_id: int|null, medio_entrada_id: int|null}
 */
class BitacoraAccesosService
{
    /**
     * Formatos de exportación disponibles
     */
    public const FORMATOS = ['pdf', 'excel'];

    public const POR_PAGINA = 50;

    /**
     * Eventos que caben en un archivo exportado
     */
    public const MAXIMO_EXPORTACION = 2000;

    public function __construct(
        private BitacoraAccesosRepositorio $bitacora,
        private ExportadorBitacoraPdf $pdf,
        private ExportadorBitacoraExcel $excel,
    ) {}

    /**
     * Guardias, viviendas y medios de entrada para los filtros
     *
     * @return array{guardias: list<array{id: int, nombre: string}>, viviendas: list<array{id: int, nombre: string}>, medios: list<array{id: int, nombre: string}>}
     */
    public function opciones(int $residencialId): array
    {
        return $this->bitacora->opciones($residencialId);
    }

    /**
     * Sin filtros de fecha, la bitácora muestra la última semana hasta hoy
     *
     * @return array{desde: string, hasta: string}
     */
    public function fechasPredeterminadas(): array
    {
        $hoy = CarbonImmutable::now(TurnoService::ZONA_LOCAL);

        return ['desde' => $hoy->subDays(6)->toDateString(), 'hasta' => $hoy->toDateString()];
    }

    /**
     * Página de eventos y cantidad de cada tipo con los filtros
     *
     * @param  Filtros  $filtros
     * @return array{eventos: LengthAwarePaginator<int, EventoBitacora>, totales: array{entrada: int, salida: int, incidencia: int}}
     */
    public function listado(int $residencialId, array $filtros): array
    {
        $consulta = $this->consulta($residencialId, $filtros);

        return [
            'eventos' => $this->bitacora->paginar($residencialId, $consulta, self::POR_PAGINA)
                ->through(fn (array $evento): array => $this->enHoraLocal($evento)),
            'totales' => $this->bitacora->totales($residencialId, $consulta),
        ];
    }

    /**
     * Elige el exportador del formato pedido
     */
    public function exportador(string $formato): ExportadorBitacora
    {
        return match ($formato) {
            'pdf' => $this->pdf,
            'excel' => $this->excel,
            default => throw new InvalidArgumentException("Formato no disponible: {$formato}"),
        };
    }

    /**
     * Archivo de la bitácora con los filtros en el formato pedido
     *
     * @param  Filtros  $filtros
     * @return array{contenido: string, tipo: string, nombre: string}
     *
     * @throws ValidationException si hay más eventos de los que caben en el archivo
     */
    public function exportar(int $residencialId, array $filtros, string $formato): array
    {
        $exportador = $this->exportador($formato);
        $consulta = $this->consulta($residencialId, $filtros);
        $totales = $this->bitacora->totales($residencialId, $consulta);

        if (array_sum($totales) > self::MAXIMO_EXPORTACION) {
            throw ValidationException::withMessages(['exportar' => 'Hay más de '.self::MAXIMO_EXPORTACION.' registros. Reduce el rango de fechas o agrega filtros para exportar.']);
        }

        $eventos = array_map(
            fn (array $evento): array => $this->enHoraLocal($evento),
            $this->bitacora->todos($residencialId, $consulta, self::MAXIMO_EXPORTACION),
        );

        $bitacora = new BitacoraAccesos($this->nombresDeFiltros($residencialId, $filtros), $totales, $eventos);

        return [
            'contenido' => $exportador->exportar($bitacora),
            'tipo' => $exportador->tipoContenido(),
            'nombre' => "bitacora-de-accesos-{$filtros['desde']}_{$filtros['hasta']}.".$exportador->extension(),
        ];
    }

    /**
     * Filtros para la consulta: días completos en UTC y el código de la vivienda
     *
     * @param  Filtros  $filtros
     * @return array{desde: string, hasta: string, tipo: string|null, turno: string|null, guardia_id: int|null, vivienda_id: int|null, vivienda_codigo: string|null, medio_entrada_id: int|null}
     */
    private function consulta(int $residencialId, array $filtros): array
    {
        $vivienda = $this->nombre($this->bitacora->opciones($residencialId)['viviendas'], $filtros['vivienda_id']);

        return [
            ...$filtros,
            'desde' => CarbonImmutable::parse($filtros['desde'], TurnoService::ZONA_LOCAL)->startOfDay()->utc()->format('Y-m-d H:i:s'),
            'hasta' => CarbonImmutable::parse($filtros['hasta'], TurnoService::ZONA_LOCAL)->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s'),
            'vivienda_codigo' => $vivienda,
        ];
    }

    /**
     * Filtros con los nombres del guardia, la vivienda y el medio para mostrarlos en el archivo
     *
     * @param  Filtros  $filtros
     * @return array{desde: string, hasta: string, tipo: string|null, turno: string|null, guardia: string|null, vivienda: string|null, medio: string|null}
     */
    private function nombresDeFiltros(int $residencialId, array $filtros): array
    {
        $opciones = $this->bitacora->opciones($residencialId);

        return [
            'desde' => $filtros['desde'],
            'hasta' => $filtros['hasta'],
            'tipo' => $filtros['tipo'],
            'turno' => $filtros['turno'],
            'guardia' => $this->nombre($opciones['guardias'], $filtros['guardia_id']),
            'vivienda' => $this->nombre($opciones['viviendas'], $filtros['vivienda_id']),
            'medio' => $this->nombre($opciones['medios'], $filtros['medio_entrada_id']),
        ];
    }

    /**
     * @param  list<array{id: int, nombre: string}>  $opciones
     */
    private function nombre(array $opciones, ?int $id): ?string
    {
        foreach ($opciones as $opcion) {
            if ($opcion['id'] === $id) {
                return $opcion['nombre'];
            }
        }

        return null;
    }

    /**
     * La fecha guardada en UTC pasa a la hora de Honduras como AAAA-MM-DD HH:MM
     *
     * @param  EventoBitacora  $evento
     * @return EventoBitacora
     */
    private function enHoraLocal(array $evento): array
    {
        return [...$evento, 'fecha' => CarbonImmutable::parse($evento['fecha'], 'UTC')->setTimezone(TurnoService::ZONA_LOCAL)->format('Y-m-d H:i')];
    }
}
