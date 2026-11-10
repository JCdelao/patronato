<?php

namespace Patronato\Modules\Finanzas\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Patronato\Modules\Finanzas\Models\ConceptoCobro;
use Patronato\Modules\Identidad\Services\AuditoriaService;

class ConceptoService
{
    public function __construct(
        private TarifaService $tarifas,
        private ModalidadService $modalidades,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Registra un concepto de cobro con su monto
     *
     * @param  array{nombre: string, tipo: string, monto: float|int|string, vigente_desde: string}  $datos
     *
     * @throws ValidationException
     */
    public function registrar(User $usuario, array $datos): ConceptoCobro
    {
        $desde = $this->desde($datos['vigente_desde']);

        return DB::transaction(function () use ($usuario, $datos, $desde) {
            $concepto = ConceptoCobro::query()->create([
                'residencial_id' => $usuario->residencial_id,
                'nombre' => trim($datos['nombre']),
                'tipo' => $datos['tipo'],
            ]);

            $this->tarifas->programarMonto($concepto, $this->monto($datos['monto']), $desde, $usuario->id);

            $this->auditoria->registrar($usuario, 'finanzas', 'concepto_creado', $concepto, [
                'valor_nuevo' => $this->resumen($concepto),
            ]);

            return $concepto;
        });
    }

    /**
     * Actualiza un concepto; un monto distinto crea una vigencia nueva
     *
     * @param  array{nombre: string, tipo: string, monto: float|int|string, vigente_desde: string}  $datos
     *
     * @throws ValidationException
     */
    public function actualizar(User $usuario, ConceptoCobro $concepto, array $datos): void
    {
        $desde = $this->desde($datos['vigente_desde']);

        DB::transaction(function () use ($usuario, $concepto, $datos, $desde) {
            $anterior = $this->resumen($concepto);
            $monto = $this->monto($datos['monto']);

            $concepto->update(['nombre' => trim($datos['nombre']), 'tipo' => $datos['tipo']]);

            if ($monto !== $anterior['monto']) {
                $this->tarifas->programarMonto($concepto, $monto, $desde, $usuario->id);
            }

            $nuevo = $this->resumen($concepto);

            if ($anterior !== $nuevo) {
                $this->auditoria->registrar($usuario, 'finanzas', 'concepto_editado', $concepto, [
                    'valor_anterior' => array_diff_assoc($anterior, $nuevo),
                    'valor_nuevo' => array_diff_assoc($nuevo, $anterior),
                ]);
            }
        });
    }

    /**
     * Desactiva un concepto para que no genere cargos nuevos
     */
    public function desactivar(User $usuario, ConceptoCobro $concepto): void
    {
        DB::transaction(function () use ($usuario, $concepto) {
            $concepto->update(['activo' => false]);

            $this->auditoria->registrar($usuario, 'finanzas', 'concepto_desactivado', $concepto);
        });
    }

    /**
     * Reactiva un concepto
     */
    public function reactivar(User $usuario, ConceptoCobro $concepto): void
    {
        DB::transaction(function () use ($usuario, $concepto) {
            $concepto->update(['activo' => true]);

            $this->auditoria->registrar($usuario, 'finanzas', 'concepto_reactivado', $concepto);
        });
    }

    /**
     * Borra un concepto que todavía no tiene cargos
     *
     * @throws ValidationException
     */
    public function eliminar(User $usuario, ConceptoCobro $concepto): void
    {
        if ($concepto->cargos()->exists()) {
            throw ValidationException::withMessages([
                'concepto' => 'El concepto tiene cargos generados: solo se puede desactivar.',
            ]);
        }

        DB::transaction(function () use ($usuario, $concepto) {
            $anterior = $this->resumen($concepto);

            $concepto->tarifas()->delete();
            $concepto->delete();

            $this->auditoria->registrar($usuario, 'finanzas', 'concepto_eliminado', $concepto, [
                'valor_anterior' => $anterior,
            ]);
        });
    }

    /**
     * Convierte el mes elegido (AAAA-MM) en el período desde el que rige
     *
     * @throws ValidationException
     */
    private function desde(string $mes): CarbonImmutable
    {
        $desde = CarbonImmutable::createFromFormat('!Y-m', $mes);

        if ($desde === null || $desde->toDateString() < $this->modalidades->periodoActual()->toDateString()) {
            throw ValidationException::withMessages([
                'vigente_desde' => 'El monto no puede regir desde un mes que ya pasó.',
            ]);
        }

        return $desde;
    }

    private function monto(float|int|string $monto): string
    {
        return number_format((float) $monto, 2, '.', '');
    }

    /**
     * @return array{nombre: string, tipo: string, monto: string|null, vigente_desde: string|null}
     */
    private function resumen(ConceptoCobro $concepto): array
    {
        $ultimo = $this->tarifas->ultimoMonto($concepto);

        return [
            'nombre' => $concepto->nombre,
            'tipo' => $concepto->tipo,
            'monto' => $ultimo?->monto,
            'vigente_desde' => $ultimo?->vigente_desde->toDateString(),
        ];
    }
}
