<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Persistencia\Models\Tarifa;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Repositorios\ConceptoRepositorio;
use Patronato\Persistencia\Repositorios\ViviendaRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Cuota mensual distinta para una vivienda, que prevalece sobre la de su tipo
 */
class TarifaEspecialService
{
    public function __construct(
        private ConceptoRepositorio $conceptos,
        private ViviendaRepositorio $viviendas,
        private Transaccion $transaccion,
        private TarifaService $tarifas,
        private PeriodoService $periodos,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Cuota que paga una vivienda en un período: la especial si hay una, si no la de su tipo
     *
     * @return array{monto: string, origen: 'especial'|'tipo'}|null null si no hay cuota definida
     */
    public function cuotaDe(Vivienda $vivienda, CarbonInterface $periodo): ?array
    {
        $concepto = $this->conceptos->cuotaMensualExistente($vivienda->residencial_id);

        if ($concepto === null) {
            return null;
        }

        $especial = $this->tarifas->montoVigente($concepto, $periodo, viviendaId: $vivienda->id);

        if ($especial !== null) {
            return ['monto' => $especial->monto, 'origen' => 'especial'];
        }

        $delTipo = $this->tarifas->montoVigente($concepto, $periodo, tipoViviendaId: $vivienda->tipo_vivienda_id);

        return $delTipo === null ? null : ['monto' => $delTipo->monto, 'origen' => 'tipo'];
    }

    /**
     * Montos especiales de cada vivienda, del más antiguo al más reciente
     *
     * @return array<int, list<Tarifa>>
     */
    public function porVivienda(int $residencialId): array
    {
        $concepto = $this->conceptos->cuotaMensualExistente($residencialId);
        $especiales = [];

        foreach ($concepto === null ? [] : $this->tarifas->especiales($concepto) as $tarifa) {
            $especiales[(int) $tarifa->vivienda_id][] = $tarifa;
        }

        return $especiales;
    }

    /**
     * Cuota especial que rige este mes en cada vivienda que tiene una
     *
     * @return array<int, string>
     */
    public function vigentes(int $residencialId): array
    {
        $periodo = $this->periodos->actual()->toDateString();
        $vigentes = [];

        foreach ($this->porVivienda($residencialId) as $viviendaId => $tarifas) {
            foreach ($tarifas as $tarifa) {
                $hasta = $tarifa->vigente_hasta?->toDateString();

                if ($tarifa->vigente_desde->toDateString() <= $periodo && ($hasta === null || $hasta >= $periodo)) {
                    $vigentes[$viviendaId] = $tarifa->monto;
                }
            }
        }

        return $vigentes;
    }

    /**
     * Asigna o cambia la cuota especial de una vivienda desde un mes
     *
     * @param  array{vivienda_id: int|string, monto: float|int|string, motivo: string, vigente_desde: string}  $datos
     *
     * @throws ValidationException
     */
    public function asignar(User $usuario, array $datos): void
    {
        $desde = $this->periodos->desdeMes($datos['vigente_desde']);
        $vivienda = $this->vivienda((int) $datos['vivienda_id']);
        $monto = number_format((float) $datos['monto'], 2, '.', '');
        $motivo = trim($datos['motivo']);

        $this->transaccion->ejecutar(function () use ($usuario, $vivienda, $desde, $monto, $motivo) {
            $concepto = $this->conceptoCuota($vivienda->residencial_id);
            $ultima = $this->tarifas->ultimoMonto($concepto, viviendaId: $vivienda->id);

            // Solo cuenta como anterior la cuota especial que seguía abierta
            $anterior = $ultima !== null && $ultima->vigente_hasta === null ? $ultima->monto : null;

            $this->tarifas->programarMonto($concepto, $monto, $desde, $usuario->id, viviendaId: $vivienda->id, motivo: $motivo);

            $this->auditoria->registrar($usuario, 'finanzas', 'tarifa_especial_asignada', $vivienda, [
                'valor_anterior' => ['cuota_especial' => $anterior],
                'valor_nuevo' => ['cuota_especial' => $monto, 'vigente_desde' => $desde->toDateString()],
                'motivo' => $motivo,
            ]);
        });
    }

    /**
     * Quita la cuota especial desde un mes; la vivienda vuelve a pagar la de su tipo
     *
     * @throws ValidationException
     */
    public function quitar(User $usuario, Vivienda $vivienda, string $mes): void
    {
        $desde = $this->periodos->desdeMes($mes);

        $this->transaccion->ejecutar(function () use ($usuario, $vivienda, $desde) {
            $concepto = $this->conceptoCuota($vivienda->residencial_id);
            $anterior = $this->tarifas->ultimoMonto($concepto, viviendaId: $vivienda->id);

            if ($anterior === null) {
                throw ValidationException::withMessages(['vivienda' => 'La vivienda no tiene cuota especial.']);
            }

            $this->tarifas->terminarMontoDeVivienda($concepto, $vivienda->id, $desde);

            $this->auditoria->registrar($usuario, 'finanzas', 'tarifa_especial_quitada', $vivienda, [
                'valor_anterior' => ['cuota_especial' => $anterior->monto],
                'valor_nuevo' => ['vigente_desde' => $desde->toDateString()],
            ]);
        });
    }

    /**
     * @throws ValidationException
     */
    private function vivienda(int $id): Vivienda
    {
        $vivienda = $this->viviendas->buscar($id);

        if ($vivienda === null || ! $vivienda->activa) {
            throw ValidationException::withMessages(['vivienda_id' => 'Elige una vivienda activa.']);
        }

        return $vivienda;
    }

    private function conceptoCuota(int $residencialId): ConceptoCobro
    {
        return $this->conceptos->cuotaMensual($residencialId, TarifaService::CONCEPTO_CUOTA);
    }
}
