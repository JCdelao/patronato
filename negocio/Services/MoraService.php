<?php

namespace Patronato\Negocio\Services;

use Patronato\Persistencia\Models\Cargo;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\CargoRepositorio;
use Patronato\Persistencia\Repositorios\ResidencialRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Estado al día o en mora de cada vivienda
 *
 * Reglas:
 * - Un cargo vence el día 1 de su mes más los días de gracia.
 * - Un mes está vencido si a la fecha de vencimiento todavía tiene saldo.
 * - La vivienda está en mora si tiene más meses vencidos que los meses de tolerancia.
 * - El estado se calcula con los pagos confirmados, así que cambia
 *   en cuanto se confirma o se anula un pago.
 */
class MoraService
{
    public const AL_DIA = 'al_dia';

    public const EN_MORA = 'en_mora';

    public function __construct(
        private CargoRepositorio $cargos,
        private ResidencialRepositorio $residenciales,
        private ConfiguracionService $configuracion,
        private PeriodoService $periodos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * @return array{dias_gracia: int, meses_tolerancia: int}
     */
    public function regla(int $residencialId): array
    {
        return $this->configuracion->reglaMora($residencialId);
    }

    /**
     * @param  array{dias_gracia: int, meses_tolerancia: int}  $regla
     */
    public function cambiarRegla(User $usuario, array $regla): void
    {
        $residencialId = (int) $usuario->residencial_id;
        $anterior = $this->regla($residencialId);

        if ($anterior === $regla) {
            return;
        }

        $this->transaccion->ejecutar(function () use ($usuario, $residencialId, $anterior, $regla) {
            $this->configuracion->cambiarReglaMora($residencialId, $regla);

            $this->auditoria->registrar($usuario, 'finanzas', 'regla_mora_cambiada', $this->residenciales->buscar($residencialId), [
                'valor_anterior' => $anterior,
                'valor_nuevo' => $regla,
            ]);
        });
    }

    /**
     * Estado de cada vivienda indicada
     *
     * @param  array<int, int>  $viviendaIds
     * @return array<int, array{estado: string, meses_vencidos: int, vencido: string}>
     */
    public function estados(int $residencialId, array $viviendaIds): array
    {
        $regla = $this->regla($residencialId);
        $hoy = $this->periodos->hoy()->toDateString();

        // Saldo vencido en centavos por vivienda y mes
        $vencidos = [];

        foreach ($this->cargos->conAplicadoDeViviendas($viviendaIds) as $cargo) {
            $saldo = $this->saldo($cargo);
            $vence = $cargo->periodo->toImmutable()->addDays($regla['dias_gracia'])->toDateString();

            if ($saldo > 0 && $vence <= $hoy) {
                $periodo = $cargo->periodo->toDateString();
                $vencidos[$cargo->vivienda_id][$periodo] = ($vencidos[$cargo->vivienda_id][$periodo] ?? 0) + $saldo;
            }
        }

        $estados = [];

        foreach ($viviendaIds as $viviendaId) {
            $meses = $vencidos[$viviendaId] ?? [];

            $estados[$viviendaId] = [
                'estado' => count($meses) > $regla['meses_tolerancia'] ? self::EN_MORA : self::AL_DIA,
                'meses_vencidos' => count($meses),
                'vencido' => AplicacionPagoService::monto(array_sum($meses)),
            ];
        }

        return $estados;
    }

    /**
     * @return array{estado: string, meses_vencidos: int, vencido: string}
     */
    public function estadoDe(int $residencialId, int $viviendaId): array
    {
        return $this->estados($residencialId, [$viviendaId])[$viviendaId];
    }

    /**
     * Lo que falta pagar del cargo, en centavos
     */
    private function saldo(Cargo $cargo): int
    {
        return AplicacionPagoService::centavos($cargo->monto)
            - AplicacionPagoService::centavos((string) $cargo->getAttribute('aplicado'));
    }
}
