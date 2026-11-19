<?php

namespace Patronato\Negocio\Contratos;

use Illuminate\Support\Collection;

// Principio O (abierto/cerrado): otra implementación se registra en AppServiceProvider sin cambiar el cobro
// Principio I (segregación de interfaces): solo tiene el método que el cobro necesita
/**
 * Acuerdo con la generación de cargos para conocer los meses pendientes de una vivienda
 *
 * La implementación es CargosPendientesService y se registra en AppServiceProvider.
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
