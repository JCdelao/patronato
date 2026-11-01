<?php

namespace Patronato\Modules\Finanzas\Services;

use Patronato\Modules\Finanzas\Models\ConceptoCobro;
use Patronato\Modules\Finanzas\Models\Tarifa;

class TarifaService
{
    private const CONCEPTO_CUOTA = 'Cuota mensual';

    /**
     * Devuelve la cuota vigente de cada vivienda
     *
     * @param  array<int, int>  $viviendaIds
     * @return array<int, string>
     */
    public function cuotasVigentes(array $viviendaIds): array
    {
        /** @var array<int, string> $cuotas */
        $cuotas = Tarifa::query()
            ->whereIn('vivienda_id', $viviendaIds)
            ->whereNull('vigente_hasta')
            ->pluck('monto', 'vivienda_id')
            ->all();

        return $cuotas;
    }

    /**
     * Define la cuota de una vivienda
     */
    public function asignarCuota(int $residencialId, int $viviendaId, float $monto, int $usuarioId): void
    {
        $concepto = ConceptoCobro::query()->firstOrCreate(
            ['residencial_id' => $residencialId, 'nombre' => self::CONCEPTO_CUOTA],
            ['tipo' => 'recurrente', 'es_cuota_unificada' => true],
        );

        $vigente = Tarifa::query()
            ->where('concepto_id', $concepto->id)
            ->where('vivienda_id', $viviendaId)
            ->whereNull('vigente_hasta')
            ->first();

        if ($vigente !== null && (float) $vigente->monto === $monto) {
            return;
        }

        if ($vigente !== null && $vigente->vigente_desde->isToday()) {
            $vigente->update(['monto' => $monto]);

            return;
        }

        $vigente?->update(['vigente_hasta' => now()->subDay()->toDateString()]);

        Tarifa::query()->create([
            'residencial_id' => $residencialId,
            'concepto_id' => $concepto->id,
            'vivienda_id' => $viviendaId,
            'monto' => $monto,
            'vigente_desde' => now()->toDateString(),
            'motivo' => 'Cuota definida en el padrón de viviendas',
            'created_by' => $usuarioId,
        ]);
    }

    /**
     * Elimina las tarifas de una vivienda que se va a borrar
     */
    public function eliminarDeVivienda(int $viviendaId): void
    {
        Tarifa::query()->where('vivienda_id', $viviendaId)->delete();
    }
}
