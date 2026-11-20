<?php

namespace Patronato\Negocio\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\Ingreso;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\IngresoRepositorio;
use Patronato\Persistencia\Transaccion;

class IngresoService
{
    private const POR_PAGINA = 25;

    public function __construct(
        private IngresoRepositorio $ingresos,
        private CategoriaFinancieraService $categorias,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
        private PermisoService $permisos,
    ) {}

    /**
     * Ingresos del residencial para el listado
     *
     * @return LengthAwarePaginator<int, array{id: int, fecha: string, concepto: string, categoria: string, numero_comprobante: string|null, monto: string, estado: string, de_cobro: bool}>
     */
    public function listado(string $buscar): LengthAwarePaginator
    {
        return $this->ingresos->paginar($buscar, self::POR_PAGINA)->through(fn (Ingreso $ingreso): array => [
            'id' => $ingreso->id,
            'fecha' => $ingreso->fecha->toDateString(),
            'concepto' => $ingreso->concepto,
            'categoria' => $ingreso->categoria->nombre,
            'numero_comprobante' => $ingreso->numero_comprobante,
            'monto' => $ingreso->monto,
            'estado' => $ingreso->estado,
            'de_cobro' => $ingreso->esDeCobro(),
        ]);
    }

    /**
     * Detalle del ingreso con su anulación y el cobro que lo originó
     *
     * @return array<string, mixed>
     */
    public function detalle(User $usuario, Ingreso $ingreso): array
    {
        $ingreso = $this->ingresos->conDetalle($ingreso);
        $anulacion = $ingreso->estado === Ingreso::ANULADO
            ? ($this->auditoria->ultimosPorEntidad('ingresos', [$ingreso->id], 'ingreso_anulado')[$ingreso->id] ?? null)
            : null;

        return [
            'id' => $ingreso->id,
            'fecha' => $ingreso->fecha->toDateString(),
            'concepto' => $ingreso->concepto,
            'categoria' => $ingreso->categoria->nombre,
            'monto' => $ingreso->monto,
            'numero_comprobante' => $ingreso->numero_comprobante,
            'estado' => $ingreso->estado,
            'registrado_por' => $ingreso->registrador?->name,
            'registrado_en' => $ingreso->created_at->toIso8601String(),
            'pago' => $ingreso->pago === null ? null : [
                'id' => $ingreso->pago->id,
                'numero_recibo' => $ingreso->pago->numero_recibo,
                'visible' => $this->permisos->puede($usuario, 'cobros', 'ver'),
            ],
            'anulacion' => $ingreso->estado === Ingreso::ANULADO ? [
                'motivo' => $ingreso->motivo_anulacion,
                'anulado_por' => $anulacion?->usuario?->name,
                'anulado_en' => $anulacion?->created_at->toIso8601String(),
            ] : null,
            'puede_anular' => $ingreso->estado === Ingreso::VIGENTE
                && ! $ingreso->esDeCobro()
                && $this->permisos->puede($usuario, 'tesoreria', 'anular'),
        ];
    }

    /**
     * Registra un ingreso que no viene de un cobro, como un alquiler o una donación
     *
     * @param  array{fecha: string, concepto: string, categoria_id: int|string, monto: float|int|string, numero_comprobante?: string|null}  $datos
     */
    public function registrar(User $usuario, array $datos): Ingreso
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $datos) {
            $comprobante = trim((string) ($datos['numero_comprobante'] ?? ''));

            $ingreso = $this->ingresos->crear([
                'residencial_id' => $usuario->residencial_id,
                'categoria_id' => (int) $datos['categoria_id'],
                'fecha' => $datos['fecha'],
                'concepto' => trim($datos['concepto']),
                'monto' => number_format((float) $datos['monto'], 2, '.', ''),
                'numero_comprobante' => $comprobante === '' ? null : $comprobante,
                'estado' => Ingreso::VIGENTE,
                'registrado_por' => $usuario->id,
            ]);

            $this->auditoria->registrar($usuario, 'tesoreria', 'ingreso_registrado', $ingreso, [
                'valor_nuevo' => $this->resumen($ingreso, $datos['fecha']),
            ]);

            return $ingreso;
        });
    }

    /**
     * Registra como ingreso de cuotas un pago recién confirmado
     *
     * Corre dentro de la transacción del cobro: si falla, el pago tampoco se guarda.
     */
    public function registrarDeCobro(User $usuario, Pago $pago): Ingreso
    {
        $ingreso = $this->ingresos->crear([
            'residencial_id' => $pago->residencial_id,
            'categoria_id' => $this->categorias->deCuotas($pago->residencial_id)->id,
            'pago_id' => $pago->id,
            'fecha' => $pago->fecha_pago->toDateString(),
            'concepto' => "Cuota · recibo N.º {$pago->numero_recibo} · vivienda {$pago->vivienda->codigo}",
            'monto' => $pago->monto,
            'numero_comprobante' => $pago->numero_recibo,
            'estado' => Ingreso::VIGENTE,
            // Una transferencia entra cuando tesorería la confirma, no cuando se reportó (HU-26)
            'registrado_por' => $pago->forma_pago === Pago::TRANSFERENCIA ? $usuario->id : ($pago->registrado_por ?? $usuario->id),
        ]);

        $this->auditoria->registrar($usuario, 'tesoreria', 'ingreso_registrado', $ingreso, [
            'valor_nuevo' => [...$this->resumen($ingreso, $pago->fecha_pago->toDateString()), 'pago_id' => $pago->id],
        ]);

        return $ingreso;
    }

    /**
     * Anula un ingreso registrado a mano; el de un cobro se anula anulando el pago
     *
     * @throws ValidationException si ya estaba anulado o viene de un cobro
     */
    public function anular(User $usuario, Ingreso $ingreso, string $motivo): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $ingreso, $motivo) {
            // Se relee bloqueado para que dos anulaciones a la vez no se registren ambas
            $actual = $this->ingresos->bloquearRegistro($ingreso->id);

            if ($actual?->esDeCobro()) {
                throw ValidationException::withMessages(['motivo' => 'Este ingreso viene de un cobro: se anula solicitando la anulación del pago.']);
            }

            if ($actual === null || $actual->estado !== Ingreso::VIGENTE) {
                throw ValidationException::withMessages(['motivo' => 'El ingreso ya está anulado.']);
            }

            $this->marcarAnulado($usuario, $actual, trim($motivo));
        });
    }

    /**
     * Anula el ingreso de un pago cuya anulación se aprobó
     *
     * Corre dentro de la transacción de la anulación del pago.
     */
    public function anularDeCobro(User $usuario, Pago $pago, string $motivo): void
    {
        $ingreso = $this->ingresos->bloquearDePago($pago->id);

        if ($ingreso !== null && $ingreso->estado === Ingreso::VIGENTE) {
            $this->marcarAnulado($usuario, $ingreso, $motivo);
        }
    }

    private function marcarAnulado(User $usuario, Ingreso $ingreso, string $motivo): void
    {
        $this->ingresos->actualizar($ingreso, [
            'estado' => Ingreso::ANULADO,
            'motivo_anulacion' => $motivo,
        ]);

        $this->auditoria->registrar($usuario, 'tesoreria', 'ingreso_anulado', $ingreso, [
            'valor_anterior' => ['estado' => Ingreso::VIGENTE],
            'valor_nuevo' => ['estado' => Ingreso::ANULADO],
            'motivo' => $motivo,
        ]);
    }

    /**
     * @return array{fecha: string, concepto: string, categoria_id: int, monto: string, numero_comprobante: string|null}
     */
    private function resumen(Ingreso $ingreso, string $fecha): array
    {
        return [
            'fecha' => $fecha,
            'concepto' => $ingreso->concepto,
            'categoria_id' => $ingreso->categoria_id,
            'monto' => $ingreso->monto,
            'numero_comprobante' => $ingreso->numero_comprobante,
        ];
    }
}
