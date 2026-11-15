<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Patronato\Negocio\Reportes\ConstructorReporteCobros;
use Patronato\Negocio\Reportes\ExportadorExcel;
use Patronato\Negocio\Reportes\ExportadorPdf;
use Patronato\Negocio\Reportes\ExportadorReporte;
use Patronato\Negocio\Reportes\ReporteCobros;
use Patronato\Persistencia\Repositorios\CajaRepositorio;
use Patronato\Persistencia\Repositorios\ReporteCobrosRepositorio;

/**
 * Reporte de cobros en efectivo por cobrador, para supervisar la recaudación
 */
class ReporteCobrosService
{
    /**
     * Formatos de exportación disponibles
     */
    public const FORMATOS = ['pdf', 'excel'];

    /**
     * Zona horaria de las fechas predeterminadas
     */
    private const ZONA_LOCAL = 'America/Tegucigalpa';

    public function __construct(
        private ReporteCobrosRepositorio $cobros,
        private CajaRepositorio $cajas,
        private ExportadorPdf $pdf,
        private ExportadorExcel $excel,
    ) {}

    /**
     * Cobradores que han tenido caja en el residencial
     *
     * @return list<array{id: int, nombre: string}>
     */
    public function cobradores(): array
    {
        return $this->cajas->cobradores();
    }

    /**
     * Sin filtros de fecha, el reporte muestra el mes en curso hasta hoy
     *
     * @return array{desde: string, hasta: string}
     */
    public function fechasPredeterminadas(): array
    {
        $hoy = CarbonImmutable::now(self::ZONA_LOCAL);

        return ['desde' => $hoy->startOfMonth()->toDateString(), 'hasta' => $hoy->toDateString()];
    }

    /**
     * Arma el reporte con los filtros indicados
     *
     * @param  array{cobrador_id: int|null, desde: string|null, hasta: string|null, estado_caja: string|null, agrupacion: string}  $filtros
     */
    public function generar(array $filtros): ReporteCobros
    {
        $nombre = null;

        foreach ($this->cobradores() as $cobrador) {
            if ($cobrador['id'] === $filtros['cobrador_id']) {
                $nombre = $cobrador['nombre'];
            }
        }

        return (new ConstructorReporteCobros($this->cobros))
            ->cobrador($filtros['cobrador_id'], $nombre)
            ->entre($filtros['desde'], $filtros['hasta'])
            ->estadoCierre($filtros['estado_caja'])
            ->agruparPor($filtros['agrupacion'])
            ->construir();
    }

    /**
     * Elige el exportador del formato pedido
     */
    public function exportador(string $formato): ExportadorReporte
    {
        return match ($formato) {
            'pdf' => $this->pdf,
            'excel' => $this->excel,
            default => throw new InvalidArgumentException("Formato no disponible: {$formato}"),
        };
    }

    /**
     * Archivo del reporte en el formato pedido
     *
     * @param  array{cobrador_id: int|null, desde: string|null, hasta: string|null, estado_caja: string|null, agrupacion: string}  $filtros
     * @return array{contenido: string, tipo: string, nombre: string}
     */
    public function exportar(array $filtros, string $formato): array
    {
        $exportador = $this->exportador($formato);
        $reporte = $this->generar($filtros);
        $periodo = implode('_', array_filter([$filtros['desde'], $filtros['hasta']]));

        return [
            'contenido' => $exportador->exportar($reporte),
            'tipo' => $exportador->tipoContenido(),
            'nombre' => 'cobros-por-cobrador'.($periodo === '' ? '' : "-{$periodo}").'.'.$exportador->extension(),
        ];
    }
}
