<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Persistencia\Models\Tarifa;
use Patronato\Persistencia\Repositorios\CargoRepositorio;
use Patronato\Persistencia\Repositorios\TarifaRepositorio;

class TarifaService
{
    /**
     * Nombre del concepto de la cuota mensual, cuyo monto depende del tipo de vivienda
     */
    public const CONCEPTO_CUOTA = 'Cuota mensual';

    public function __construct(
        private TarifaRepositorio $tarifas,
        private CargoRepositorio $cargos,
    ) {}

    /**
     * Montos generales de cada concepto, del más antiguo al más reciente
     *
     * @param  array<int, int>  $conceptoIds
     * @return array<int, list<Tarifa>>
     */
    public function montosGenerales(array $conceptoIds): array
    {
        $montos = [];

        foreach ($this->tarifas->generalesDe($conceptoIds) as $tarifa) {
            $montos[$tarifa->concepto_id][] = $tarifa;
        }

        return $montos;
    }

    /**
     * Montos de un concepto para cada tipo de vivienda, del más antiguo al más reciente
     *
     * @param  array<int, int>  $tipoIds
     * @return array<int, list<Tarifa>>
     */
    public function montosPorTipo(ConceptoCobro $concepto, array $tipoIds): array
    {
        $montos = [];

        foreach ($this->tarifas->deTipos($concepto->id, $tipoIds) as $tarifa) {
            $montos[(int) $tarifa->tipo_vivienda_id][] = $tarifa;
        }

        return $montos;
    }

    /**
     * Monto más reciente de un concepto, general, de un tipo o de una vivienda, aunque todavía no rija
     */
    public function ultimoMonto(ConceptoCobro $concepto, ?int $tipoViviendaId = null, ?int $viviendaId = null): ?Tarifa
    {
        return $this->tarifas->ultima($concepto->id, $tipoViviendaId, $viviendaId);
    }

    /**
     * Monto de un concepto que rige en un período: general, de un tipo o de una vivienda
     */
    public function montoVigente(ConceptoCobro $concepto, CarbonInterface $periodo, ?int $tipoViviendaId = null, ?int $viviendaId = null): ?Tarifa
    {
        return $this->tarifas->vigenteEn($concepto->id, $tipoViviendaId, $periodo->toDateString(), $viviendaId);
    }

    /**
     * Montos especiales de un concepto, del más antiguo al más reciente
     *
     * @return list<Tarifa>
     */
    public function especiales(ConceptoCobro $concepto): array
    {
        return array_values($this->tarifas->especiales($concepto->id)->all());
    }

    /**
     * Define el monto de un concepto desde un período, para todas las viviendas, para un tipo o para una vivienda
     *
     * Los montos posteriores todavía no rigen y se reemplazan. Un monto que ya
     * empieza en ese período solo se corrige si no tiene cargos generados.
     *
     * @throws ValidationException
     */
    public function programarMonto(
        ConceptoCobro $concepto,
        string $monto,
        CarbonInterface $desde,
        int $usuarioId,
        ?int $tipoViviendaId = null,
        ?int $viviendaId = null,
        ?string $motivo = null,
    ): void {
        $fecha = $desde->toDateString();

        $this->tarifas->borrarPosteriores($concepto->id, $tipoViviendaId, $fecha, $viviendaId);

        $mismo = $this->tarifas->desde($concepto->id, $tipoViviendaId, $fecha, $viviendaId);

        if ($mismo !== null) {
            $this->exigirSinCargos($concepto, $fecha, $tipoViviendaId, $viviendaId);

            $this->tarifas->actualizar($mismo, ['monto' => $monto, 'motivo' => $motivo, 'vigente_hasta' => null]);

            return;
        }

        $this->tarifas->cerrarAnteriores($concepto->id, $tipoViviendaId, $fecha, $desde->copy()->subDay()->toDateString(), $viviendaId);

        $this->tarifas->crear([
            'residencial_id' => $concepto->residencial_id,
            'concepto_id' => $concepto->id,
            'tipo_vivienda_id' => $tipoViviendaId,
            'vivienda_id' => $viviendaId,
            'monto' => $monto,
            'vigente_desde' => $fecha,
            'motivo' => $motivo,
            'created_by' => $usuarioId,
        ]);
    }

    /**
     * Deja de aplicar el monto de una vivienda desde un período
     *
     * @throws ValidationException
     */
    public function terminarMontoDeVivienda(ConceptoCobro $concepto, int $viviendaId, CarbonInterface $desde): void
    {
        $fecha = $desde->toDateString();

        $this->tarifas->borrarPosteriores($concepto->id, null, $fecha, $viviendaId);

        $mismo = $this->tarifas->desde($concepto->id, null, $fecha, $viviendaId);

        if ($mismo !== null) {
            $this->exigirSinCargos($concepto, $fecha, null, $viviendaId);

            $this->tarifas->eliminar($mismo);
        }

        $this->tarifas->cerrarAnteriores($concepto->id, null, $fecha, $desde->copy()->subDay()->toDateString(), $viviendaId);
    }

    /**
     * @throws ValidationException
     */
    private function exigirSinCargos(ConceptoCobro $concepto, string $fecha, ?int $tipoViviendaId, ?int $viviendaId): void
    {
        if ($this->cargos->existenDe($concepto->id, $fecha, $tipoViviendaId, $viviendaId)) {
            throw ValidationException::withMessages([
                'vigente_desde' => 'Ya hay cargos generados con el monto de ese mes: elige un mes posterior.',
            ]);
        }
    }

    /**
     * Elimina las tarifas de una vivienda que se va a borrar
     */
    public function eliminarDeVivienda(int $viviendaId): void
    {
        $this->tarifas->eliminarDeVivienda($viviendaId);
    }

    /**
     * Elimina las tarifas de un concepto que se va a borrar
     */
    public function eliminarDeConcepto(int $conceptoId): void
    {
        $this->tarifas->eliminarDeConcepto($conceptoId);
    }

    /**
     * Elimina las cuotas de un tipo de vivienda que se va a borrar
     */
    public function eliminarDeTipo(int $tipoViviendaId): void
    {
        $this->tarifas->eliminarDeTipo($tipoViviendaId);
    }
}
