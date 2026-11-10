<?php

namespace Patronato\Modules\Finanzas\Contratos;

use Illuminate\Support\Collection;

/**
 * Acuerdo con la generación de cargos para conocer los meses pendientes de una vivienda
 *
 * La implementación real la aporta el servicio que genera los cargos y se registra
 * en AppServiceProvider en lugar de SinCargosPendientes.
 */
interface CargosPendientes
{
    /**
     * Meses con saldo pendiente de una vivienda del residencial del usuario
     *
     * - Ordenados del período más antiguo al más reciente.
     * - pendiente = monto del cargo menos lo aplicado por pagos en estado 'confirmado'.
     * - No incluye los cargos con pendiente en cero.
     * - Una colección vacía significa que la vivienda no debe nada.
     * - periodo usa el formato Y-m-d (siempre día 1); monto y pendiente, dos decimales.
     *
     * @return Collection<int, array{cargo_id: int, periodo: string, concepto: string, monto: string, pendiente: string}>
     *
     * @throws CargosNoIntegrados si todavía no existe la generación de cargos
     */
    public function pendientesDeVivienda(int $viviendaId): Collection;
}
