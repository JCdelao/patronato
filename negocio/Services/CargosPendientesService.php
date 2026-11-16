<?php

namespace Patronato\Negocio\Services;

use Illuminate\Support\Collection;
use Patronato\Negocio\Contratos\CargosPendientes;
use Patronato\Persistencia\Models\Cargo;
use Patronato\Persistencia\Repositorios\CargoRepositorio;

/**
 * Meses que una vivienda todavía debe, a partir de los cargos generados
 *
 * Lo pendiente de cada cargo es su monto menos lo que cubren los pagos confirmados.
 */
class CargosPendientesService implements CargosPendientes
{
    public function __construct(private CargoRepositorio $cargos) {}

    public function pendientesDeVivienda(int $viviendaId): Collection
    {
        return $this->cargos->conAplicadoDe($viviendaId)
            ->map(function (Cargo $cargo): array {
                $pendiente = AplicacionPagoService::centavos($cargo->monto)
                    - AplicacionPagoService::centavos((string) $cargo->getAttribute('aplicado'));

                return [
                    'cargo_id' => $cargo->id,
                    'periodo' => $cargo->periodo->toDateString(),
                    'concepto' => $cargo->concepto->nombre,
                    'monto' => $cargo->monto,
                    'pendiente' => AplicacionPagoService::monto(max(0, $pendiente)),
                ];
            })
            ->filter(fn (array $cargo): bool => $cargo['pendiente'] !== '0.00')
            ->values()
            ->toBase();
    }
}
