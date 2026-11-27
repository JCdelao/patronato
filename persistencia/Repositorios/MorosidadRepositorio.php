<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\Vivienda;

/**
 * Viviendas con saldo pendiente según la vista oficial de saldos
 */
class MorosidadRepositorio extends Repositorio
{
    /**
     * Viviendas del residencial con saldo mayor que cero, con su responsable de pago
     *
     * Cada vivienda trae los atributos saldo y total_por_verificar de v_saldos_vivienda.
     *
     * @return Collection<int, Vivienda>
     */
    public function conSaldoPendiente(int $residencialId): Collection
    {
        return Vivienda::query()
            ->join('v_saldos_vivienda as saldos', 'saldos.vivienda_id', '=', 'viviendas.id')
            ->where('viviendas.residencial_id', $residencialId)
            ->where('saldos.saldo', '>', 0)
            ->select('viviendas.*', 'saldos.saldo', 'saldos.total_por_verificar')
            ->with(['vinculosVigentes' => fn ($query) => $query->where('es_responsable_pago', true)->with('residente')])
            ->orderBy('viviendas.codigo')
            ->get();
    }
}
