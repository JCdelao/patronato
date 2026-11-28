<?php

namespace Patronato\Persistencia\Repositorios;

use Patronato\Persistencia\Models\Pago;

/**
 * Consultas del reporte de recaudación por efectivo y transferencia
 */
class RecaudacionRepositorio
{
    /**
     * Pagos del residencial con fecha de pago dentro del rango, en cualquier estado
     *
     * Solo lee la tabla pagos, sin aplicaciones, así cada pago aparece una vez.
     * Con un cobrador, solo entra el efectivo que él registró.
     *
     * @return list<array{pago_id: int, forma_pago: string, estado: string, fecha_pago: string, monto: string}>
     */
    public function pagosEntre(int $residencialId, string $desde, string $hasta, ?int $cobradorId): array
    {
        $filas = Pago::query()
            ->where('pagos.residencial_id', $residencialId)
            ->whereDate('pagos.fecha_pago', '>=', $desde)
            ->whereDate('pagos.fecha_pago', '<=', $hasta)
            ->when($cobradorId !== null, fn ($query) => $query
                ->where('pagos.forma_pago', Pago::EFECTIVO)
                ->where('pagos.registrado_por', $cobradorId))
            ->orderBy('pagos.fecha_pago')
            ->orderBy('pagos.id')
            ->toBase()
            ->get(['pagos.id', 'pagos.forma_pago', 'pagos.estado', 'pagos.fecha_pago', 'pagos.monto']);

        $resultado = [];

        foreach ($filas as $fila) {
            $resultado[] = [
                'pago_id' => (int) $fila->id,
                'forma_pago' => (string) $fila->forma_pago,
                'estado' => (string) $fila->estado,
                'fecha_pago' => substr((string) $fila->fecha_pago, 0, 10),
                'monto' => (string) $fila->monto,
            ];
        }

        return $resultado;
    }
}
