<?php

namespace Patronato\Negocio\Services;

use InvalidArgumentException;
use Patronato\Negocio\Reportes\ExportadorMorosidad;
use Patronato\Negocio\Reportes\ExportadorMorosidadExcel;
use Patronato\Negocio\Reportes\ExportadorMorosidadPdf;
use Patronato\Negocio\Reportes\ReporteMorosidad;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;
use Patronato\Persistencia\Repositorios\CargoRepositorio;
use Patronato\Persistencia\Repositorios\MorosidadRepositorio;
use Patronato\Persistencia\Repositorios\ResidencialRepositorio;

/**
 * Reporte de las viviendas con saldo pendiente, para organizar las gestiones de cobro
 *
 * Reglas:
 * - Entran las viviendas activas e inactivas con saldo oficial mayor que cero (v_saldos_vivienda).
 * - Los meses adeudados son los períodos que todavía tienen algo sin pagar, vencidos o no;
 *   la deuda empieza en el más antiguo.
 * - Los meses vencidos, lo vencido y el estado al día o en mora salen de MoraService.
 * - Los pagos por verificar se muestran aparte y no reducen el saldo.
 * - Orden por monto: mayor saldo primero. Por antigüedad: la deuda más vieja primero.
 * - Cada exportación queda en la auditoría.
 */
class ReporteMorosidadService
{
    public const POR_MONTO = 'monto';

    public const POR_ANTIGUEDAD = 'antiguedad';

    public const ORDENES = [self::POR_MONTO, self::POR_ANTIGUEDAD];

    public const FORMATOS = ['pdf', 'excel'];

    public function __construct(
        private MorosidadRepositorio $morosidad,
        private CargoRepositorio $cargos,
        private ResidencialRepositorio $residenciales,
        private MoraService $mora,
        private PeriodoService $periodos,
        private AuditoriaService $auditoria,
        private ExportadorMorosidadPdf $pdf,
        private ExportadorMorosidadExcel $excel,
    ) {}

    /**
     * Arma el reporte del residencial en el orden pedido
     */
    public function generar(int $residencialId, string $orden = self::POR_MONTO): ReporteMorosidad
    {
        $viviendas = $this->morosidad->conSaldoPendiente($residencialId);
        $ids = $viviendas->modelKeys();

        $estados = $ids === [] ? [] : $this->mora->estados($residencialId, $ids);
        $meses = $this->mesesAdeudados($ids);

        $filas = $viviendas->map(function (Vivienda $vivienda) use ($estados, $meses): array {
            /** @var ViviendaResidente|null $vinculo */
            $vinculo = $vivienda->vinculosVigentes->first();
            $periodos = $meses[$vivienda->id] ?? [];
            $mora = $estados[$vivienda->id];

            return [
                'vivienda_id' => $vivienda->id,
                'codigo' => $vivienda->codigo,
                'activa' => $vivienda->activa,
                'responsable' => $vinculo === null ? null : trim($vinculo->residente->nombres.' '.$vinculo->residente->apellidos),
                'saldo' => AplicacionPagoService::monto(AplicacionPagoService::centavos((string) $vivienda->getAttribute('saldo'))),
                'por_verificar' => AplicacionPagoService::monto(AplicacionPagoService::centavos((string) $vivienda->getAttribute('total_por_verificar'))),
                'meses_adeudados' => count($periodos),
                'deuda_desde' => $periodos === [] ? null : min($periodos),
                'meses_vencidos' => $mora['meses_vencidos'],
                'vencido' => $mora['vencido'],
                'estado' => $mora['estado'],
            ];
        })->all();

        usort($filas, fn (array $a, array $b): int => $orden === self::POR_ANTIGUEDAD
            ? [$a['deuda_desde'] ?? '9999-12-31', -AplicacionPagoService::centavos($a['saldo']), $a['codigo']] <=> [$b['deuda_desde'] ?? '9999-12-31', -AplicacionPagoService::centavos($b['saldo']), $b['codigo']]
            : [-AplicacionPagoService::centavos($a['saldo']), $a['codigo']] <=> [-AplicacionPagoService::centavos($b['saldo']), $b['codigo']]);

        return new ReporteMorosidad(
            residencial: $this->residenciales->buscar($residencialId)->nombre,
            orden: $orden,
            fechaCorte: $this->periodos->hoy()->toDateString(),
            viviendas: $filas,
            total: AplicacionPagoService::monto(array_sum(array_map(fn (array $fila): int => AplicacionPagoService::centavos($fila['saldo']), $filas))),
            totalVencido: AplicacionPagoService::monto(array_sum(array_map(fn (array $fila): int => AplicacionPagoService::centavos($fila['vencido']), $filas))),
            enMora: count(array_filter($filas, fn (array $fila): bool => $fila['estado'] === MoraService::EN_MORA)),
        );
    }

    /**
     * Elige el exportador del formato pedido
     */
    public function exportador(string $formato): ExportadorMorosidad
    {
        return match ($formato) {
            'pdf' => $this->pdf,
            'excel' => $this->excel,
            default => throw new InvalidArgumentException("Formato no disponible: {$formato}"),
        };
    }

    /**
     * Archivo del reporte en el formato pedido; la exportación queda en la auditoría
     *
     * @return array{contenido: string, tipo: string, nombre: string}
     */
    public function exportar(User $usuario, string $orden, string $formato): array
    {
        $exportador = $this->exportador($formato);
        $residencialId = (int) $usuario->residencial_id;
        $reporte = $this->generar($residencialId, $orden);

        $contenido = $exportador->exportar($reporte);

        $this->auditoria->registrar($usuario, 'reportes', 'reporte_morosidad_exportado', $this->residenciales->buscar($residencialId), [
            'valor_nuevo' => [
                'formato' => $formato,
                'orden' => $orden,
                'fecha_corte' => $reporte->fechaCorte,
                'viviendas' => count($reporte->viviendas),
                'total' => $reporte->total,
            ],
        ]);

        return [
            'contenido' => $contenido,
            'tipo' => $exportador->tipoContenido(),
            'nombre' => "morosidad-{$reporte->fechaCorte}.".$exportador->extension(),
        ];
    }

    /**
     * Períodos con algo sin pagar de cada vivienda
     *
     * @param  array<int, int>  $viviendaIds
     * @return array<int, list<string>>
     */
    private function mesesAdeudados(array $viviendaIds): array
    {
        if ($viviendaIds === []) {
            return [];
        }

        $meses = [];

        foreach ($this->cargos->conAplicadoDeViviendas($viviendaIds) as $cargo) {
            $pendiente = AplicacionPagoService::centavos($cargo->monto) - AplicacionPagoService::centavos((string) $cargo->getAttribute('aplicado'));

            if ($pendiente > 0) {
                $meses[$cargo->vivienda_id][$cargo->periodo->toDateString()] = true;
            }
        }

        return array_map(fn (array $periodos): array => array_keys($periodos), $meses);
    }
}
