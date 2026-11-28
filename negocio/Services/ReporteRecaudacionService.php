<?php

namespace Patronato\Negocio\Services;

use InvalidArgumentException;
use Patronato\Negocio\Reportes\ReporteRecaudacion;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Repositorios\CajaRepositorio;
use Patronato\Persistencia\Repositorios\RecaudacionRepositorio;

/**
 * Recaudación diaria, mensual o anual por efectivo y transferencia, para controlar los ingresos por cuotas
 *
 * Reglas:
 * - Solo suman los pagos confirmados, incluidos los migrados.
 * - Las transferencias pendientes de verificar o reportadas por el vecino van en un bloque aparte.
 * - Los anulados y rechazados no suman en ningún total; se informan aparte con cantidad y monto.
 * - Todo se agrupa por fecha_pago, la misma fecha del ingreso de cuotas.
 * - El filtro por cobrador solo aplica al efectivo: con un cobrador elegido no entran transferencias.
 * - Sin fechas, el reporte muestra el mes en curso hasta hoy.
 */
class ReporteRecaudacionService
{
    public const POR_DIA = 'dia';

    public const POR_MES = 'mes';

    public const POR_ANIO = 'anio';

    public const AGRUPACIONES = [self::POR_DIA, self::POR_MES, self::POR_ANIO];

    /**
     * Estados que esperan la verificación de tesorería, en el orden en que se muestran
     */
    private const PENDIENTES = [Pago::PENDIENTE_VERIFICACION, Pago::REPORTADO_POR_VECINO];

    /**
     * Estados que no tienen efecto en la recaudación, en el orden en que se muestran
     */
    private const SIN_EFECTO = [Pago::ANULADO, Pago::RECHAZADO];

    public function __construct(
        private RecaudacionRepositorio $pagos,
        private CajaRepositorio $cajas,
        private PeriodoService $periodos,
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
     * El mes en curso hasta hoy, en la hora de Honduras
     *
     * @return array{desde: string, hasta: string}
     */
    public function fechasPredeterminadas(): array
    {
        $hoy = $this->periodos->hoy();

        return ['desde' => $hoy->startOfMonth()->toDateString(), 'hasta' => $hoy->toDateString()];
    }

    /**
     * Arma el reporte del residencial con los filtros indicados
     *
     * @param  array{desde: string, hasta: string, cobrador_id: int|null, agrupacion: string}  $filtros
     */
    public function generar(int $residencialId, array $filtros): ReporteRecaudacion
    {
        if (! in_array($filtros['agrupacion'], self::AGRUPACIONES, true)) {
            throw new InvalidArgumentException("Agrupación no válida: {$filtros['agrupacion']}");
        }

        /** @var array<string, array{pagos: int, efectivo: int, transferencia: int}> $grupos */
        $grupos = [];
        $totales = ['pagos' => 0, Pago::EFECTIVO => 0, Pago::TRANSFERENCIA => 0];
        $pendientes = array_fill_keys(self::PENDIENTES, ['pagos' => 0, 'centavos' => 0]);
        $sinEfecto = array_fill_keys(self::SIN_EFECTO, ['pagos' => 0, 'centavos' => 0]);

        foreach ($this->pagos->pagosEntre($residencialId, $filtros['desde'], $filtros['hasta'], $filtros['cobrador_id']) as $pago) {
            $centavos = AplicacionPagoService::centavos($pago['monto']);

            if (isset($pendientes[$pago['estado']])) {
                $pendientes[$pago['estado']]['pagos']++;
                $pendientes[$pago['estado']]['centavos'] += $centavos;

                continue;
            }

            if (isset($sinEfecto[$pago['estado']])) {
                $sinEfecto[$pago['estado']]['pagos']++;
                $sinEfecto[$pago['estado']]['centavos'] += $centavos;

                continue;
            }

            // Solo lo confirmado es dinero recaudado
            if ($pago['estado'] !== Pago::CONFIRMADO) {
                continue;
            }

            $periodo = $this->periodoDe($pago['fecha_pago'], $filtros['agrupacion']);
            $forma = $pago['forma_pago'] === Pago::TRANSFERENCIA ? Pago::TRANSFERENCIA : Pago::EFECTIVO;

            $grupos[$periodo] ??= ['pagos' => 0, Pago::EFECTIVO => 0, Pago::TRANSFERENCIA => 0];
            $grupos[$periodo]['pagos']++;
            $grupos[$periodo][$forma] += $centavos;

            $totales['pagos']++;
            $totales[$forma] += $centavos;
        }

        ksort($grupos);

        $periodos = [];

        foreach ($grupos as $periodo => $grupo) {
            $periodos[] = ['periodo' => (string) $periodo, ...$this->montos($grupo)];
        }

        return new ReporteRecaudacion(
            filtros: [
                'desde' => $filtros['desde'],
                'hasta' => $filtros['hasta'],
                'cobrador_id' => $filtros['cobrador_id'],
                'cobrador' => $this->nombreCobrador($filtros['cobrador_id']),
                'agrupacion' => $filtros['agrupacion'],
            ],
            periodos: $periodos,
            totales: $this->montos($totales),
            pendientes: [
                'pagos' => array_sum(array_column($pendientes, 'pagos')),
                'monto' => AplicacionPagoService::monto(array_sum(array_column($pendientes, 'centavos'))),
                'estados' => $this->porEstado($pendientes),
            ],
            sinEfecto: $this->porEstado($sinEfecto),
        );
    }

    /**
     * Día (Y-m-d), mes (Y-m) o año (Y) de la fecha de pago
     */
    private function periodoDe(string $fecha, string $agrupacion): string
    {
        return match ($agrupacion) {
            self::POR_MES => substr($fecha, 0, 7),
            self::POR_ANIO => substr($fecha, 0, 4),
            default => $fecha,
        };
    }

    /**
     * @param  array{pagos: int, efectivo: int, transferencia: int}  $grupo
     * @return array{pagos: int, efectivo: string, transferencia: string, total: string}
     */
    private function montos(array $grupo): array
    {
        return [
            'pagos' => $grupo['pagos'],
            'efectivo' => AplicacionPagoService::monto($grupo[Pago::EFECTIVO]),
            'transferencia' => AplicacionPagoService::monto($grupo[Pago::TRANSFERENCIA]),
            'total' => AplicacionPagoService::monto($grupo[Pago::EFECTIVO] + $grupo[Pago::TRANSFERENCIA]),
        ];
    }

    /**
     * @param  array<string, array{pagos: int, centavos: int}>  $estados
     * @return list<array{estado: string, pagos: int, monto: string}>
     */
    private function porEstado(array $estados): array
    {
        $resultado = [];

        foreach ($estados as $estado => $grupo) {
            $resultado[] = ['estado' => $estado, 'pagos' => $grupo['pagos'], 'monto' => AplicacionPagoService::monto($grupo['centavos'])];
        }

        return $resultado;
    }

    private function nombreCobrador(?int $cobradorId): ?string
    {
        foreach ($this->cobradores() as $cobrador) {
            if ($cobrador['id'] === $cobradorId) {
                return $cobrador['nombre'];
            }
        }

        return null;
    }
}
