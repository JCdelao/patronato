<?php

namespace Patronato\Modules\Finanzas\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Patronato\Modules\Finanzas\Models\ConceptoCobro;
use Patronato\Modules\Finanzas\Models\Tarifa;

class TarifaService
{
    public const CONCEPTO_CUOTA = 'Cuota mensual';

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
     * Montos generales de cada concepto, del más antiguo al más reciente
     *
     * @param  array<int, int>  $conceptoIds
     * @return array<int, list<Tarifa>>
     */
    public function montosGenerales(array $conceptoIds): array
    {
        $montos = [];

        foreach ($this->generales()->whereIn('concepto_id', $conceptoIds)->orderBy('vigente_desde')->get() as $tarifa) {
            $montos[$tarifa->concepto_id][] = $tarifa;
        }

        return $montos;
    }

    /**
     * Monto general más reciente de un concepto, aunque todavía no rija
     */
    public function ultimoMonto(ConceptoCobro $concepto): ?Tarifa
    {
        return $this->generales()
            ->where('concepto_id', $concepto->id)
            ->orderByDesc('vigente_desde')
            ->first();
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

        $this->generales()->where('concepto_id', $concepto->id)->whereDate('vigente_desde', '>', $fecha)->delete();

        $mismo = $this->generales()->where('concepto_id', $concepto->id)->whereDate('vigente_desde', $fecha)->first();

        if ($mismo !== null) {
            if ($concepto->cargos()->whereDate('periodo', '>=', $fecha)->exists()) {
                throw ValidationException::withMessages([
                    'vigente_desde' => 'Ya hay cargos generados con el monto de ese mes: elige un mes posterior.',
                ]);
            }

            $mismo->update(['monto' => $monto, 'vigente_hasta' => null]);

            return;
        }

        $this->generales()
            ->where('concepto_id', $concepto->id)
            ->whereDate('vigente_desde', '<', $fecha)
            ->where(fn (Builder $query) => $query->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $fecha))
            ->update(['vigente_hasta' => $desde->copy()->subDay()->toDateString()]);

        Tarifa::query()->create([
            'residencial_id' => $concepto->residencial_id,
            'concepto_id' => $concepto->id,
            'monto' => $monto,
            'vigente_desde' => $fecha,
            'created_by' => $usuarioId,
        ]);
    }

    /**
     * Tarifas que aplican a todas las viviendas
     *
     * @return Builder<Tarifa>
     */
    private function generales(): Builder
    {
        return Tarifa::query()->whereNull('tipo_vivienda')->whereNull('vivienda_id');
    }

    /**
     * Elimina las tarifas de una vivienda que se va a borrar
     */
    public function eliminarDeVivienda(int $viviendaId): void
    {
        Tarifa::query()->where('vivienda_id', $viviendaId)->delete();
    }
}
