<?php

namespace Patronato\Modules\Padron\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Patronato\Modules\Finanzas\Services\TarifaService;
use Patronato\Modules\Identidad\Services\AuditoriaService;
use Patronato\Modules\Padron\Models\Vivienda;

class ViviendaService
{
    public function __construct(
        private TarifaService $tarifas,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Registra una vivienda con su cuota mensual
     *
     * @param  array{codigo: string, tipo: string, edificio_torre?: string|null, calle_bloque?: string|null, numero: string, estado_ocupacion: string, cuota: float|int|string}  $datos
     */
    public function registrar(User $usuario, array $datos): Vivienda
    {
        return DB::transaction(function () use ($usuario, $datos) {
            $vivienda = Vivienda::query()->create([
                'residencial_id' => $usuario->residencial_id,
                ...$this->datosVivienda($datos),
            ]);

            $this->tarifas->asignarCuota((int) $usuario->residencial_id, $vivienda->id, (float) $datos['cuota'], $usuario->id);

            $this->auditoria->registrar($usuario, 'padron', 'vivienda_registrada', $vivienda, [
                'valor_nuevo' => $this->resumen($vivienda),
            ]);

            return $vivienda;
        });
    }

    /**
     * Actualiza los datos y la cuota de una vivienda
     *
     * @param  array{codigo: string, tipo: string, edificio_torre?: string|null, calle_bloque?: string|null, numero: string, estado_ocupacion: string, cuota: float|int|string}  $datos
     */
    public function actualizar(User $usuario, Vivienda $vivienda, array $datos): void
    {
        DB::transaction(function () use ($usuario, $vivienda, $datos) {
            $anterior = $this->resumen($vivienda);

            $vivienda->update($this->datosVivienda($datos));

            $this->tarifas->asignarCuota($vivienda->residencial_id, $vivienda->id, (float) $datos['cuota'], $usuario->id);

            $nuevo = $this->resumen($vivienda);

            if ($anterior !== $nuevo) {
                $this->auditoria->registrar($usuario, 'padron', 'vivienda_editada', $vivienda, [
                    'valor_anterior' => array_diff_assoc($anterior, $nuevo),
                    'valor_nuevo' => array_diff_assoc($nuevo, $anterior),
                ]);
            }
        });
    }

    /**
     * Reactiva una vivienda
     */
    public function reactivar(User $usuario, Vivienda $vivienda): void
    {
        DB::transaction(function () use ($usuario, $vivienda) {
            $vivienda->update(['activa' => true]);

            $this->auditoria->registrar($usuario, 'padron', 'vivienda_reactivada', $vivienda);
        });
    }

    /**
     * Borra una vivienda o la deja inactiva
     *
     * @throws ValidationException
     */
    public function eliminar(User $usuario, Vivienda $vivienda): bool
    {
        if ($vivienda->vinculosVigentes()->exists()) {
            throw ValidationException::withMessages([
                'vivienda' => 'La vivienda tiene residentes: dalos de baja antes de eliminarla.',
            ]);
        }

        $anterior = $this->resumen($vivienda);

        try {
            DB::transaction(function () use ($usuario, $vivienda, $anterior) {
                $this->tarifas->eliminarDeVivienda($vivienda->id);
                $vivienda->delete();

                $this->auditoria->registrar($usuario, 'padron', 'vivienda_eliminada', $vivienda, [
                    'valor_anterior' => $anterior,
                ]);
            });

            return true;
        } catch (QueryException) {
            // Si otra tabla la referencia, queda inactiva
            $vivienda->exists = true;

            DB::transaction(function () use ($usuario, $vivienda) {
                $vivienda->update(['activa' => false]);

                $this->auditoria->registrar($usuario, 'padron', 'vivienda_inactivada', $vivienda, [
                    'motivo' => 'La vivienda tiene historial y no se puede borrar',
                ]);
            });

            return false;
        }
    }

    /**
     * @param  array{codigo: string, tipo: string, edificio_torre?: string|null, calle_bloque?: string|null, numero: string, estado_ocupacion: string}  $datos
     * @return array<string, string|null>
     */
    private function datosVivienda(array $datos): array
    {
        $esApartamento = $datos['tipo'] === 'apartamento';

        return [
            'codigo' => $datos['codigo'],
            'tipo' => $datos['tipo'],
            'edificio_torre' => $esApartamento ? $datos['edificio_torre'] ?? null : null,
            'calle_bloque' => $esApartamento ? null : $datos['calle_bloque'] ?? null,
            'numero' => $datos['numero'],
            'estado_ocupacion' => $datos['estado_ocupacion'],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function resumen(Vivienda $vivienda): array
    {
        return [
            'codigo' => $vivienda->codigo,
            'tipo' => $vivienda->tipo,
            'edificio_torre' => $vivienda->edificio_torre,
            'calle_bloque' => $vivienda->calle_bloque,
            'numero' => $vivienda->numero,
            'estado_ocupacion' => $vivienda->estado_ocupacion,
            'cuota' => $this->tarifas->cuotasVigentes([$vivienda->id])[$vivienda->id] ?? null,
        ];
    }
}
