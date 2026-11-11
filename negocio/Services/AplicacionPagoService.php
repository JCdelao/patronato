<?php

namespace Patronato\Negocio\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Patronato\Negocio\Contratos\CargosNoIntegrados;
use Patronato\Negocio\Contratos\CargosPendientes;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Repositorios\PagoRepositorio;

/**
 * Reparte un pago entre los cargos de la vivienda
 *
 * Por ahora solo aplica a los meses elegidos. Falta agregar la aplicación
 * automática a los meses más antiguos y el saldo a favor.
 */
class AplicacionPagoService
{
    public function __construct(
        private CargosPendientes $cargos,
        private PagoRepositorio $pagos,
    ) {}

    /**
     * Aplica el pago a los meses elegidos, del más antiguo al más reciente
     *
     * El último mes puede quedar cubierto en parte. Debe llamarse dentro de la
     * transacción que guarda el pago.
     *
     * @param  array<int, int>  $cargoIds
     *
     * @throws ValidationException
     * @throws CargosNoIntegrados
     */
    public function aplicar(Pago $pago, array $cargoIds): void
    {
        $restante = self::centavos($pago->monto);
        $elegidos = $this->elegidos($pago->vivienda_id, $cargoIds);

        if ($restante > $this->total($elegidos)) {
            throw ValidationException::withMessages(['monto' => 'El monto supera lo pendiente de los meses elegidos.']);
        }

        foreach ($elegidos as $cargo) {
            $aplicado = min($restante, self::centavos($cargo['pendiente']));

            if ($aplicado === 0) {
                break;
            }

            $this->pagos->aplicar([
                'residencial_id' => $pago->residencial_id,
                'pago_id' => $pago->id,
                'cargo_id' => $cargo['cargo_id'],
                'monto' => self::monto($aplicado),
            ]);

            $restante -= $aplicado;
        }
    }

    /**
     * Total pendiente, en centavos, de los meses elegidos de una vivienda
     *
     * @param  array<int, int>  $cargoIds
     *
     * @throws ValidationException
     * @throws CargosNoIntegrados
     */
    public function pendienteDe(int $viviendaId, array $cargoIds): int
    {
        return $this->total($this->elegidos($viviendaId, $cargoIds));
    }

    /**
     * Convierte un monto con dos decimales a centavos
     */
    public static function centavos(string $monto): int
    {
        return (int) round((float) $monto * 100);
    }

    /**
     * Convierte centavos a un monto con dos decimales
     */
    public static function monto(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }

    /**
     * Meses elegidos que siguen pendientes, en el orden que da la generación de cargos
     *
     * @param  array<int, int>  $cargoIds
     * @return Collection<int, array{cargo_id: int, periodo: string, concepto: string, monto: string, pendiente: string}>
     *
     * @throws ValidationException
     */
    private function elegidos(int $viviendaId, array $cargoIds): Collection
    {
        $cargoIds = array_values(array_unique($cargoIds));

        if ($cargoIds === []) {
            throw ValidationException::withMessages(['cargos' => 'Elige al menos un mes.']);
        }

        $elegidos = $this->cargos->pendientesDeVivienda($viviendaId)
            ->filter(fn (array $cargo): bool => in_array($cargo['cargo_id'], $cargoIds, true))
            ->values();

        // Rechaza meses de otra vivienda, de otro residencial o ya pagados
        if ($elegidos->count() !== count($cargoIds)) {
            throw ValidationException::withMessages(['cargos' => 'Uno de los meses elegidos no está pendiente para esta vivienda.']);
        }

        return $elegidos;
    }

    /**
     * @param  Collection<int, array{cargo_id: int, periodo: string, concepto: string, monto: string, pendiente: string}>  $cargos
     */
    private function total(Collection $cargos): int
    {
        return $cargos->sum(fn (array $cargo): int => self::centavos($cargo['pendiente']));
    }
}
