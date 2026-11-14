<?php

namespace Patronato\Persistencia\Repositorios;

use Patronato\Persistencia\Models\Pago;

/**
 * Consultas del reporte de cobros en efectivo por cobrador
 */
class ReporteCobrosRepositorio
{
    /**
     * Cobros en efectivo del residencial con su cobrador, vivienda y estado de caja
     *
     * Los filtros nulos no se aplican. Las fechas son de fecha_pago, que ya es la de Honduras.
     *
     * @param  array{cobrador_id: int|null, desde: string|null, hasta: string|null, estado_caja: string|null}  $filtros
     * @return list<array{pago_id: int, numero_recibo: string|null, fecha_pago: string, monto: string, estado: string, cobrador_id: int, cobrador: string, vivienda: string, estado_caja: string}>
     */
    public function cobrosEnEfectivo(array $filtros): array
    {
        $filas = Pago::query()
            ->join('cajas', 'cajas.id', '=', 'pagos.caja_id')
            ->join('users', 'users.id', '=', 'cajas.cobrador_id')
            ->join('viviendas', 'viviendas.id', '=', 'pagos.vivienda_id')
            ->where('pagos.forma_pago', Pago::EFECTIVO)
            ->when($filtros['cobrador_id'] !== null, fn ($query) => $query->where('cajas.cobrador_id', $filtros['cobrador_id']))
            ->when($filtros['desde'] !== null, fn ($query) => $query->whereDate('pagos.fecha_pago', '>=', $filtros['desde']))
            ->when($filtros['hasta'] !== null, fn ($query) => $query->whereDate('pagos.fecha_pago', '<=', $filtros['hasta']))
            ->when($filtros['estado_caja'] !== null, fn ($query) => $query->where('cajas.estado', $filtros['estado_caja']))
            ->orderBy('users.name')
            ->orderBy('pagos.fecha_pago')
            ->orderBy('pagos.id')
            ->toBase()
            ->get([
                'pagos.id as pago_id',
                'pagos.numero_recibo',
                'pagos.fecha_pago',
                'pagos.monto',
                'pagos.estado',
                'cajas.cobrador_id',
                'users.name as cobrador',
                'viviendas.codigo as vivienda',
                'cajas.estado as estado_caja',
            ]);

        $resultado = [];

        foreach ($filas as $fila) {
            $resultado[] = [
                'pago_id' => (int) $fila->pago_id,
                'numero_recibo' => $fila->numero_recibo === null ? null : (string) $fila->numero_recibo,
                'fecha_pago' => substr((string) $fila->fecha_pago, 0, 10),
                'monto' => (string) $fila->monto,
                'estado' => (string) $fila->estado,
                'cobrador_id' => (int) $fila->cobrador_id,
                'cobrador' => (string) $fila->cobrador,
                'vivienda' => (string) $fila->vivienda,
                'estado_caja' => (string) $fila->estado_caja,
            ];
        }

        return $resultado;
    }
}
