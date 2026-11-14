<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\CargoRepositorio;
use Patronato\Persistencia\Repositorios\ConceptoRepositorio;
use Patronato\Persistencia\Transaccion;

class ConceptoService
{
    public function __construct(
        private ConceptoRepositorio $conceptos,
        private CargoRepositorio $cargos,
        private Transaccion $transaccion,
        private TarifaService $tarifas,
        private PeriodoService $periodos,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Conceptos del residencial con la marca de si ya tienen cargos
     *
     * @return Collection<int, ConceptoCobro>
     */
    public function catalogo(): Collection
    {
        return $this->conceptos->catalogo();
    }

    /**
     * Motivo por el que no se puede usar el nombre, o null si está libre
     *
     * No se puede repetir otro concepto, sin importar mayúsculas, ni usar el de la cuota mensual.
     */
    public function motivoNombreNoDisponible(string $nombre, ?int $conceptoId): ?string
    {
        $nombre = trim($nombre);

        if (Str::lower($nombre) === Str::lower(TarifaService::CONCEPTO_CUOTA)) {
            return 'Ese nombre está reservado para la cuota de cada vivienda.';
        }

        return $this->conceptos->existeNombre($nombre, $conceptoId) ? 'Ya existe un concepto con ese nombre.' : null;
    }

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

        return $this->transaccion->ejecutar(function () use ($usuario, $datos, $desde) {
            $concepto = $this->conceptos->crear([
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

        $this->transaccion->ejecutar(function () use ($usuario, $concepto, $datos, $desde) {
            $anterior = $this->resumen($concepto);
            $monto = $this->monto($datos['monto']);

            $this->conceptos->actualizar($concepto, ['nombre' => trim($datos['nombre']), 'tipo' => $datos['tipo']]);

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
        $this->transaccion->ejecutar(function () use ($usuario, $concepto) {
            $this->conceptos->actualizar($concepto, ['activo' => false]);

            $this->auditoria->registrar($usuario, 'finanzas', 'concepto_desactivado', $concepto);
        });
    }

    /**
     * Reactiva un concepto
     */
    public function reactivar(User $usuario, ConceptoCobro $concepto): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $concepto) {
            $this->conceptos->actualizar($concepto, ['activo' => true]);

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
        if ($this->cargos->existenDe($concepto->id)) {
            throw ValidationException::withMessages([
                'concepto' => 'El concepto tiene cargos generados: solo se puede desactivar.',
            ]);
        }

        $this->transaccion->ejecutar(function () use ($usuario, $concepto) {
            $anterior = $this->resumen($concepto);

            $this->tarifas->eliminarDeConcepto($concepto->id);
            $this->conceptos->eliminar($concepto);

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

        if ($desde === null || $desde->toDateString() < $this->periodos->actual()->toDateString()) {
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
