<?php

namespace Patronato\Negocio\Reportes;

use InvalidArgumentException;
use Patronato\Negocio\Services\AplicacionPagoService;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Repositorios\ReporteCobrosRepositorio;

// Patrón Builder (creacional): el reporte se arma paso a paso y se crea con construir()
/**
 * Arma el reporte de cobros paso a paso con filtros opcionales
 *
 * Uso: (new ConstructorReporteCobros($repositorio))->cobrador(3)->entre($desde, $hasta)->agruparPor('mes')->construir()
 */
class ConstructorReporteCobros
{
    public const POR_DIA = 'dia';

    public const POR_MES = 'mes';

    private ?int $cobradorId = null;

    private ?string $cobradorNombre = null;

    private ?string $desde = null;

    private ?string $hasta = null;

    private ?string $estadoCaja = null;

    private string $agrupacion = self::POR_DIA;

    public function __construct(private ReporteCobrosRepositorio $cobros) {}

    /**
     * Solo los cobros de un cobrador; null para todos
     */
    public function cobrador(?int $id, ?string $nombre = null): static
    {
        $this->cobradorId = $id;
        $this->cobradorNombre = $id === null ? null : $nombre;

        return $this;
    }

    /**
     * Rango de fechas del cobro (Y-m-d), con ambos extremos incluidos
     */
    public function entre(?string $desde, ?string $hasta): static
    {
        $this->desde = $desde;
        $this->hasta = $hasta;

        return $this;
    }

    /**
     * Solo las cajas abiertas o cerradas; null para todas
     */
    public function estadoCierre(?string $estado): static
    {
        if ($estado !== null && ! in_array($estado, [Caja::ABIERTA, Caja::CERRADA], true)) {
            throw new InvalidArgumentException("Estado de caja no válido: {$estado}");
        }

        $this->estadoCaja = $estado;

        return $this;
    }

    public function agruparPor(string $agrupacion): static
    {
        if (! in_array($agrupacion, [self::POR_DIA, self::POR_MES], true)) {
            throw new InvalidArgumentException("Agrupación no válida: {$agrupacion}");
        }

        $this->agrupacion = $agrupacion;

        return $this;
    }

    public function construir(): ReporteCobros
    {
        $detalle = $this->cobros->cobrosEnEfectivo([
            'cobrador_id' => $this->cobradorId,
            'desde' => $this->desde,
            'hasta' => $this->hasta,
            'estado_caja' => $this->estadoCaja,
        ]);

        /** @var array<string, array{cobrador_id: int, cobrador: string, periodo: string, cobros: int, centavos: int}> $grupos */
        $grupos = [];
        /** @var array<int, array{cobrador_id: int, cobrador: string, cobros: int, centavos: int}> $porCobrador */
        $porCobrador = [];
        $total = 0;
        $cobros = 0;
        $anulados = 0;
        $montoAnulado = 0;

        foreach ($detalle as $cobro) {
            $centavos = AplicacionPagoService::centavos($cobro['monto']);

            // Solo lo confirmado es dinero recaudado
            if ($cobro['estado'] !== Pago::CONFIRMADO) {
                $anulados++;
                $montoAnulado += $centavos;

                continue;
            }

            $periodo = $this->agrupacion === self::POR_MES ? substr($cobro['fecha_pago'], 0, 7) : $cobro['fecha_pago'];
            $clave = "{$cobro['cobrador_id']}|{$periodo}";

            $grupos[$clave] ??= ['cobrador_id' => $cobro['cobrador_id'], 'cobrador' => $cobro['cobrador'], 'periodo' => $periodo, 'cobros' => 0, 'centavos' => 0];
            $grupos[$clave]['cobros']++;
            $grupos[$clave]['centavos'] += $centavos;

            $porCobrador[$cobro['cobrador_id']] ??= ['cobrador_id' => $cobro['cobrador_id'], 'cobrador' => $cobro['cobrador'], 'cobros' => 0, 'centavos' => 0];
            $porCobrador[$cobro['cobrador_id']]['cobros']++;
            $porCobrador[$cobro['cobrador_id']]['centavos'] += $centavos;

            $cobros++;
            $total += $centavos;
        }

        return new ReporteCobros(
            filtros: [
                'desde' => $this->desde,
                'hasta' => $this->hasta,
                'cobrador' => $this->cobradorNombre,
                'estado_caja' => $this->estadoCaja,
                'agrupacion' => $this->agrupacion,
            ],
            resumen: array_values(array_map(fn (array $grupo): array => [
                'cobrador_id' => $grupo['cobrador_id'],
                'cobrador' => $grupo['cobrador'],
                'periodo' => $grupo['periodo'],
                'cobros' => $grupo['cobros'],
                'total' => AplicacionPagoService::monto($grupo['centavos']),
            ], $grupos)),
            subtotales: array_values(array_map(fn (array $grupo): array => [
                'cobrador_id' => $grupo['cobrador_id'],
                'cobrador' => $grupo['cobrador'],
                'cobros' => $grupo['cobros'],
                'total' => AplicacionPagoService::monto($grupo['centavos']),
            ], $porCobrador)),
            totalCobros: $cobros,
            total: AplicacionPagoService::monto($total),
            anulados: $anulados,
            montoAnulado: AplicacionPagoService::monto($montoAnulado),
            detalle: $detalle,
        );
    }
}
