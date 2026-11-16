<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonInterface;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Persistencia\Models\GeneracionCargos;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\CargoRepositorio;
use Patronato\Persistencia\Repositorios\ConceptoRepositorio;
use Patronato\Persistencia\Repositorios\GeneracionCargosRepositorio;
use Patronato\Persistencia\Repositorios\ResidencialRepositorio;
use Patronato\Persistencia\Repositorios\ViviendaRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Generación de los cargos mensuales de cada vivienda
 *
 * Reglas:
 * - Cada vivienda activa recibe la cuota de su tipo, o su cuota especial si tiene una.
 * - Además recibe cada concepto activo: los recurrentes cada mes y los únicos
 *   solo en el mes desde el que rige su monto.
 * - Un concepto que se cobra solo a algunas viviendas llega únicamente a las elegidas.
 * - Las viviendas inactivas no reciben cargos y no se crean cargos de L. 0.
 * - Generar otra vez el mismo período no duplica: solo crea los que falten,
 *   por ejemplo de una vivienda registrada después.
 * - El monto queda guardado en el cargo; un cambio de tarifa posterior no lo altera.
 */
class GeneracionCargosService
{
    public function __construct(
        private ViviendaRepositorio $viviendas,
        private ConceptoRepositorio $conceptos,
        private CargoRepositorio $cargos,
        private GeneracionCargosRepositorio $generaciones,
        private ResidencialRepositorio $residenciales,
        private Transaccion $transaccion,
        private TarifaService $tarifas,
        private TarifaEspecialService $especiales,
        private ConfiguracionService $configuracion,
        private PeriodoService $periodos,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Genera los cargos del mes en curso que falten, a pedido del tesorero
     *
     * @return int cargos creados
     */
    public function generarAhora(User $usuario): int
    {
        return $this->generar((int) $usuario->residencial_id, $this->periodos->actual(), $usuario);
    }

    /**
     * Genera el mes en curso de cada residencial activo cuyo día ya llegó y que todavía no lo tiene
     *
     * Se ejecuta varias veces al día; si el servidor estuvo apagado, se pone al día en la siguiente ejecución.
     *
     * @return array<int, int> cargos creados por residencial
     */
    public function generarPendientes(): array
    {
        $periodo = $this->periodos->actual();
        $hoy = $this->periodos->diaDeHoy();
        $resultado = [];

        foreach ($this->residenciales->activos() as $residencial) {
            $toca = $hoy >= $this->configuracion->diaGeneracionCargos($residencial->id);

            if ($toca && ! $this->generaciones->existe($residencial->id, $periodo->toDateString())) {
                $resultado[$residencial->id] = $this->generar($residencial->id, $periodo, null);
            }
        }

        return $resultado;
    }

    /**
     * Día del mes en que se generan los cargos del residencial
     */
    public function dia(int $residencialId): int
    {
        return $this->configuracion->diaGeneracionCargos($residencialId);
    }

    public function cambiarDia(User $usuario, int $dia): void
    {
        $residencialId = (int) $usuario->residencial_id;
        $anterior = $this->dia($residencialId);

        if ($anterior === $dia) {
            return;
        }

        $this->transaccion->ejecutar(function () use ($usuario, $residencialId, $anterior, $dia) {
            $this->configuracion->cambiarDiaGeneracionCargos($residencialId, $dia);

            $this->auditoria->registrar($usuario, 'finanzas', 'dia_generacion_cambiado', $this->residenciales->buscar($residencialId), [
                'valor_anterior' => ['dia_generacion_cargos' => $anterior],
                'valor_nuevo' => ['dia_generacion_cargos' => $dia],
            ]);
        });
    }

    /**
     * Última generación del mes en curso, si ya se hizo
     */
    public function delMesActual(int $residencialId): ?GeneracionCargos
    {
        return $this->generaciones->ultima($residencialId, $this->periodos->actual()->toDateString());
    }

    /**
     * @return int cargos creados
     */
    private function generar(int $residencialId, CarbonInterface $periodo, ?User $usuario): int
    {
        return $this->transaccion->ejecutar(function () use ($residencialId, $periodo, $usuario) {
            $creados = 0;
            $fecha = $periodo->toDateString();
            $cuota = $this->conceptos->cuotaMensualExistente($residencialId);
            $conceptos = $this->conceptos->cobrablesDe($residencialId);

            // El monto de cada concepto es el mismo para todas las viviendas que lo pagan
            $montos = $conceptos->mapWithKeys(fn (ConceptoCobro $concepto) => [$concepto->id => $this->montoDelConcepto($concepto, $periodo)]);

            // Los conceptos que se cobran solo a algunas viviendas, con las viviendas elegidas
            $soloAlgunas = array_values($conceptos->where('alcance', ConceptoCobro::ALGUNAS)->modelKeys());
            $elegidas = $this->conceptos->viviendasElegidas($soloAlgunas);

            foreach ($this->viviendas->activasDe($residencialId) as $vivienda) {
                $cuotaVivienda = $cuota === null ? null : $this->especiales->cuotaDe($vivienda, $periodo);

                if ($cuota !== null && $cuotaVivienda !== null) {
                    $creados += (int) $this->crear($residencialId, $vivienda->id, $cuota->id, $fecha, $cuotaVivienda['monto']);
                }

                foreach ($montos as $conceptoId => $monto) {
                    $laPaga = ! in_array($conceptoId, $soloAlgunas, true) || isset($elegidas[$conceptoId][$vivienda->id]);

                    if ($monto !== null && $laPaga) {
                        $creados += (int) $this->crear($residencialId, $vivienda->id, (int) $conceptoId, $fecha, $monto);
                    }
                }
            }

            $generacion = $this->generaciones->registrar([
                'residencial_id' => $residencialId,
                'periodo' => $fecha,
                'cargos' => $creados,
                'automatica' => $usuario === null,
                'user_id' => $usuario?->id,
            ]);

            $datos = ['valor_nuevo' => ['periodo' => $fecha, 'cargos' => $creados]];

            if ($usuario === null) {
                $this->auditoria->registrarSistema($residencialId, 'finanzas', 'cargos_generados', $generacion, $datos);
            } else {
                $this->auditoria->registrar($usuario, 'finanzas', 'cargos_generados', $generacion, $datos);
            }

            return $creados;
        });
    }

    /**
     * Monto que se cobra de un concepto en el período, o null si ese mes no se cobra
     */
    private function montoDelConcepto(ConceptoCobro $concepto, CarbonInterface $periodo): ?string
    {
        $tarifa = $this->tarifas->montoVigente($concepto, $periodo);

        if ($tarifa === null) {
            return null;
        }

        // Un concepto único solo se cobra en el mes desde el que rige su monto
        if ($concepto->tipo === ConceptoCobro::UNICO && $tarifa->vigente_desde->toDateString() !== $periodo->toDateString()) {
            return null;
        }

        return $tarifa->monto;
    }

    private function crear(int $residencialId, int $viviendaId, int $conceptoId, string $periodo, string $monto): bool
    {
        if ((float) $monto <= 0) {
            return false;
        }

        return $this->cargos->crearSiNoExiste([
            'residencial_id' => $residencialId,
            'vivienda_id' => $viviendaId,
            'concepto_id' => $conceptoId,
            'periodo' => $periodo,
            'monto' => $monto,
        ]);
    }
}
