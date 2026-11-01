<?php

namespace Patronato\Modules\Padron\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Patronato\Modules\Finanzas\Services\TarifaService;
use Patronato\Modules\Padron\Models\Vivienda;

class ViviendaService
{
    public function __construct(private TarifaService $tarifas) {}

    /**
     * Registra una vivienda con su cuota mensual (HU-10).
     *
     * @param  array{codigo: string, tipo: string, direccion: string, cuota: float|int|string}  $datos
     */
    public function registrar(int $residencialId, array $datos, int $usuarioId): Vivienda
    {
        return DB::transaction(function () use ($residencialId, $datos, $usuarioId) {
            $vivienda = Vivienda::query()->create([
                'residencial_id' => $residencialId,
                'codigo' => $datos['codigo'],
                'tipo' => $datos['tipo'],
                'calle_bloque' => $datos['direccion'],
            ]);

            $this->tarifas->asignarCuota($residencialId, $vivienda->id, (float) $datos['cuota'], $usuarioId);

            return $vivienda;
        });
    }

    /**
     * Actualiza los datos y la cuota de una vivienda.
     *
     * @param  array{codigo: string, tipo: string, direccion: string, cuota: float|int|string}  $datos
     */
    public function actualizar(Vivienda $vivienda, array $datos, int $usuarioId): void
    {
        DB::transaction(function () use ($vivienda, $datos, $usuarioId) {
            $vivienda->update([
                'codigo' => $datos['codigo'],
                'tipo' => $datos['tipo'],
                'calle_bloque' => $datos['direccion'],
            ]);

            $this->tarifas->asignarCuota($vivienda->residencial_id, $vivienda->id, (float) $datos['cuota'], $usuarioId);
        });
    }

    /**
     * Vuelve a activar una vivienda; conserva sus datos y su cuota.
     */
    public function reactivar(Vivienda $vivienda): void
    {
        $vivienda->update(['activa' => true]);
    }

    /**
     * Borra la vivienda o, si ya tiene historial, la marca como inactiva (HU-10).
     *
     * @return bool true si se borró; false si quedó inactiva
     *
     * @throws ValidationException si todavía tiene residentes
     */
    public function eliminar(Vivienda $vivienda): bool
    {
        if ($vivienda->vinculosVigentes()->exists()) {
            throw ValidationException::withMessages([
                'vivienda' => 'La vivienda tiene residentes: dalos de baja antes de eliminarla.',
            ]);
        }

        try {
            DB::transaction(function () use ($vivienda) {
                $this->tarifas->eliminarDeVivienda($vivienda->id);
                $vivienda->delete();
            });

            return true;
        } catch (QueryException) {
            // Otra tabla la referencia (pagos, cargos, historial): se conserva como inactiva.
            $vivienda->exists = true;
            $vivienda->update(['activa' => false]);

            return false;
        }
    }
}
