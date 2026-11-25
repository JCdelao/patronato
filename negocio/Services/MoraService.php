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
            if ($saldo > 0 && $this->vence($cargo, $regla['dias_gracia']) <= $hoy) {
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

    public function mesesParaAcceso(int $residencialId, int $viviendaId, bool $contarPendientes): ?int
    {
        $gracia = $this->regla($residencialId)['dias_gracia'];
        $hoy = $this->periodos->hoy()->toDateString();
        $cargos = $this->cargos->paraEvaluarAcceso($residencialId, $viviendaId, $contarPendientes);
        $primero = $cargos->first();
        if ($primero === null || $primero->periodo->toDateString() > $hoy) {
            return null;
        }

        $meses = [];
        $aplicado = 0;
        foreach ($cargos as $cargo) {
            $monto = AplicacionPagoService::centavos($cargo->monto);
            $saldo = $this->saldo($cargo);
            if ($monto < 0 || $saldo < 0 || $saldo > $monto) {
                return null;
            }
            $aplicado += $monto - $saldo;
            if ($saldo > 0 && $this->vence($cargo, $gracia) <= $hoy) {
                $periodo = $cargo->periodo->toDateString();
                $meses[$periodo] = ($meses[$periodo] ?? 0) + $saldo;
            }
        }

        $confirmado = AplicacionPagoService::centavos((string) $primero->getAttribute('total_pagos_confirmados'));
        $ajustes = AplicacionPagoService::centavos((string) $primero->getAttribute('total_ajustes'));
        if ($aplicado > $confirmado || $ajustes !== 0 || ($meses !== [] && $aplicado < $confirmado)) {
            // Un saldo sin aplicar o ajustes sin asignación mensual requieren revisión.
            return null;
        }

        $pendiente = $contarPendientes ? AplicacionPagoService::centavos((string) $primero->getAttribute('total_por_verificar')) : 0;
        if ($pendiente < 0) {
            return null;
        }
        // Proyección para acceso solamente. Cubre primero los meses vencidos más antiguos.
        foreach ($meses as $periodo => $saldo) {
            if ($pendiente < $saldo) {
                break;
            }
            $pendiente -= $saldo;
            unset($meses[$periodo]);
        }

        return count($meses);
    }

    private function vence(Cargo $cargo, int $gracia): string
    {
        return $cargo->periodo->toImmutable()->addDays($gracia)->toDateString();
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
