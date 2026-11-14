<?php

namespace Patronato\Negocio\Services;

use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\CajaRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Cierre de caja: compara lo registrado en el sistema con el efectivo entregado
 */
class CierreCajaService
{
    /**
     * Cajas cerradas que se muestran en la lista
     */
    private const CERRADAS_RECIENTES = 20;

    public function __construct(
        private CajaRepositorio $cajas,
        private NumeracionReciboService $recibos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Cajas abiertas y últimas cerradas del residencial, resumidas para la lista
     *
     * @return array{abiertas: list<array<string, mixed>>, cerradas: list<array<string, mixed>>}
     */
    public function resumen(User $usuario): array
    {
        $resumir = fn (Caja $caja): array => [
            'id' => $caja->id,
            'cobrador' => $caja->cobrador->name,
            'abierta_en' => $caja->abierta_en->toIso8601String(),
            'cerrada_en' => $caja->cerrada_en?->toIso8601String(),
            'cobros' => $caja->pagos->count(),
            'total_registrado' => $caja->total_registrado ?? $this->totalRegistrado($caja),
            'diferencia' => $caja->diferencia,
            'propia' => $caja->cobrador_id === $usuario->id,
        ];

        return [
            'abiertas' => array_values($this->cajas->abiertas()->map($resumir)->all()),
            'cerradas' => array_values($this->cajas->cerradasRecientes(self::CERRADAS_RECIENTES)->map($resumir)->all()),
        ];
    }

    /**
     * Cobros incluidos, total registrado y, si está cerrada, lo entregado y la diferencia
     *
     * @return array<string, mixed>
     */
    public function detalle(User $usuario, Caja $caja): array
    {
        $caja = $this->cajas->conDetalle($caja);

        return [
            'id' => $caja->id,
            'estado' => $caja->estado,
            'cobrador' => $caja->cobrador->name,
            'abierta_en' => $caja->abierta_en->toIso8601String(),
            'cerrada_en' => $caja->cerrada_en?->toIso8601String(),
            'cerrada_por' => $caja->cerradaPor?->name,
            'cobros' => array_values($caja->pagos->map(fn (Pago $pago): array => [
                'id' => $pago->id,
                'numero_recibo' => $pago->numero_recibo,
                'vivienda' => $pago->vivienda->codigo,
                'monto' => $pago->monto,
                'estado' => $pago->estado,
                'registrado_en' => $pago->created_at->toIso8601String(),
            ])->all()),
            'total_registrado' => $caja->total_registrado ?? $this->totalRegistrado($caja),
            'monto_entregado' => $caja->monto_entregado,
            'diferencia' => $caja->diferencia,
            'justificacion' => $caja->justificacion,
            'puede_cerrar' => $this->puedeCerrar($usuario, $caja),
        ];
    }

    /**
     * Solo se cierra una caja abierta, y nadie cierra su propia caja
     */
    public function puedeCerrar(User $usuario, Caja $caja): bool
    {
        return $caja->estado === Caja::ABIERTA && $caja->cobrador_id !== $usuario->id;
    }

    /**
     * Cierra la caja con el efectivo entregado y deja el cierre auditado
     *
     * Si lo entregado no coincide con lo registrado, la justificación es obligatoria.
     *
     * @throws ValidationException
     */
    public function cerrar(User $usuario, Caja $caja, string $montoEntregado, ?string $justificacion): Caja
    {
        $residencialId = (int) $caja->residencial_id;

        return $this->transaccion->ejecutar(function () use ($usuario, $caja, $residencialId, $montoEntregado, $justificacion) {
            // Mismo bloqueo que el cobro: ningún cobro entra a la caja mientras se cierra
            $this->recibos->bloquear($residencialId);

            $caja = $this->cajas->bloquear($caja->id) ?? $caja;

            if ($caja->estado !== Caja::ABIERTA) {
                throw ValidationException::withMessages(['caja' => 'Esta caja ya está cerrada.']);
            }

            if ($caja->cobrador_id === $usuario->id) {
                throw ValidationException::withMessages(['caja' => 'No puedes cerrar tu propia caja. Otro usuario con permiso de cierre debe hacerlo.']);
            }

            $caja = $this->cajas->conDetalle($caja);

            $registrado = $this->centavosRegistrados($caja);
            $entregado = AplicacionPagoService::centavos($montoEntregado);
            $diferencia = $entregado - $registrado;
            $justificacion = trim((string) $justificacion) ?: null;

            if ($diferencia !== 0 && $justificacion === null) {
                $tipo = $diferencia < 0 ? 'faltante' : 'sobrante';

                throw ValidationException::withMessages([
                    'justificacion' => "Hay un {$tipo} de L ".AplicacionPagoService::monto(abs($diferencia)).'. Escribe la justificación.',
                ]);
            }

            $this->cajas->actualizar($caja, [
                'estado' => Caja::CERRADA,
                'cerrada_en' => now(),
                'cerrada_por' => $usuario->id,
                'total_registrado' => AplicacionPagoService::monto($registrado),
                'monto_entregado' => AplicacionPagoService::monto($entregado),
                'justificacion' => $justificacion,
            ]);

            $this->auditoria->registrar($usuario, 'cajas', 'caja_cerrada', $caja, [
                'valor_anterior' => ['estado' => Caja::ABIERTA],
                'valor_nuevo' => [
                    'estado' => Caja::CERRADA,
                    'cobros' => $caja->pagos->count(),
                    'total_registrado' => AplicacionPagoService::monto($registrado),
                    'monto_entregado' => AplicacionPagoService::monto($entregado),
                    'diferencia' => AplicacionPagoService::monto($diferencia),
                    'justificacion' => $justificacion,
                ],
            ]);

            return $caja;
        });
    }

    /**
     * Suma de los cobros confirmados; los anulados no se entregan
     */
    private function totalRegistrado(Caja $caja): string
    {
        return AplicacionPagoService::monto($this->centavosRegistrados($caja));
    }

    private function centavosRegistrados(Caja $caja): int
    {
        return $caja->pagos
            ->where('estado', Pago::CONFIRMADO)
            ->sum(fn (Pago $pago): int => AplicacionPagoService::centavos($pago->monto));
    }
}
