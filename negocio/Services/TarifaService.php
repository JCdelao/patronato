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
     * Nombre del concepto con la cuota que cada vivienda tenía en el padrón
     *
     * Sus montos se conservan hasta que se definan las tarifas por tipo de vivienda.
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
     * Monto general más reciente de un concepto, aunque todavía no rija
     */
    public function ultimoMonto(ConceptoCobro $concepto): ?Tarifa
    {
        return $this->tarifas->ultimaGeneral($concepto->id);
    }

    /**
     * Define el monto general de un concepto desde un período
     *
     * Los montos posteriores todavía no rigen y se reemplazan. Un monto que ya
     * empieza en ese período solo se corrige si no tiene cargos generados.
     *
     * @throws ValidationException
     */
    public function programarMonto(ConceptoCobro $concepto, string $monto, CarbonInterface $desde, int $usuarioId): void
    {
        $fecha = $desde->toDateString();

        $this->tarifas->borrarGeneralesPosteriores($concepto->id, $fecha);

        $mismo = $this->tarifas->generalDesde($concepto->id, $fecha);

        if ($mismo !== null) {
            if ($this->cargos->existenDe($concepto->id, $fecha)) {
                throw ValidationException::withMessages([
                    'vigente_desde' => 'Ya hay cargos generados con el monto de ese mes: elige un mes posterior.',
                ]);
            }

            $this->tarifas->actualizar($mismo, ['monto' => $monto, 'vigente_hasta' => null]);

            return;
        }

        $this->tarifas->cerrarGeneralesAnteriores($concepto->id, $fecha, $desde->copy()->subDay()->toDateString());

        $this->tarifas->crear([
            'residencial_id' => $concepto->residencial_id,
            'concepto_id' => $concepto->id,
            'monto' => $monto,
            'vigente_desde' => $fecha,
            'created_by' => $usuarioId,
        ]);
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
}
