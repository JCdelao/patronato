<?php

namespace Patronato\Negocio\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\SolicitudAnulacion;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\PagoRepositorio;
use Patronato\Persistencia\Repositorios\SolicitudAnulacionRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Anulación de pagos con doble control
 *
 * Reglas:
 * - Un directivo con cobros.anular solicita la anulación con un motivo.
 * - Otro directivo con cobros.aprobar la aprueba o la rechaza. No puede ser quien
 *   la solicitó ni el cobrador que registró el pago.
 * - Mientras no se aprueba, el pago sigue confirmado y cuenta en el saldo.
 * - Al aprobarse, el pago pasa a 'anulado'. No se borra nada: el saldo y los meses
 *   pendientes solo cuentan los pagos confirmados, así que el efecto se revierte solo.
 * - Un pago de una caja cerrada también se anula; el cierre conserva su total.
 */
class AnulacionPagoService
{
    public const MOTIVO_MINIMO = 10;

    private const POR_PAGINA = 25;

    public function __construct(
        private PagoRepositorio $pagos,
        private SolicitudAnulacionRepositorio $solicitudes,
        private PermisoService $permisos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Pagos del residencial para la lista de cobros
     *
     * @return LengthAwarePaginator<int, array{id: int, numero_recibo: string|null, fecha_pago: string, vivienda: string, monto: string, forma_pago: string, estado: string, cobrador: string|null}>
     */
    public function listado(string $buscar): LengthAwarePaginator
    {
        $pagina = $this->pagos->paginar($buscar, self::POR_PAGINA);

        return $pagina->through(fn (Pago $pago): array => [
            'id' => $pago->id,
            'numero_recibo' => $pago->numero_recibo,
            'fecha_pago' => $pago->fecha_pago->toDateString(),
            'vivienda' => $pago->vivienda->codigo,
            'monto' => $pago->monto,
            'forma_pago' => $pago->forma_pago,
            'estado' => $pago->estado,
            'cobrador' => $pago->cobrador?->name,
        ]);
    }

    /**
     * Detalle del pago con su anulación, sus solicitudes y el saldo de la vivienda
     *
     * @return array<string, mixed>
     */
    public function detalle(User $usuario, Pago $pago): array
    {
        $pago = $this->pagos->conAnulacion($pago);

        return [
            'id' => $pago->id,
            'numero_recibo' => $pago->numero_recibo,
            'fecha_pago' => $pago->fecha_pago->toDateString(),
            'registrado_en' => $pago->created_at->toIso8601String(),
            'vivienda' => $pago->vivienda->codigo,
            'monto' => $pago->monto,
            'forma_pago' => $pago->forma_pago,
            'estado' => $pago->estado,
            'cobrador' => $pago->cobrador?->name,
            'caja_cerrada' => $pago->caja?->estado === Caja::CERRADA,
            'saldo_vivienda' => $this->pagos->saldoDeVivienda($pago->vivienda_id),
            'anulacion' => $pago->estado === Pago::ANULADO ? [
                'solicitada_por' => $pago->anuladoPor?->name,
                'aprobada_por' => $pago->aprobadorAnulacion?->name,
                'anulado_en' => $pago->anulado_en?->toIso8601String(),
                'motivo' => $pago->motivo_anulacion,
            ] : null,
            'solicitudes' => array_values($pago->solicitudesAnulacion->map(fn (SolicitudAnulacion $solicitud): array => [
                'id' => $solicitud->id,
                'estado' => $solicitud->estado,
                'motivo' => $solicitud->motivo,
                'solicitada_por' => $solicitud->solicitante->name,
                'solicitada_en' => $solicitud->created_at->toIso8601String(),
                'resuelta_por' => $solicitud->resolutor?->name,
                'resuelta_en' => $solicitud->resuelta_en?->toIso8601String(),
                'motivo_rechazo' => $solicitud->motivo_rechazo,
            ])->all()),
            'puede_solicitar' => $this->puedeSolicitar($usuario, $pago),
        ];
    }

    /**
     * Solicitudes pendientes, indicando cuáles puede resolver el usuario
     *
     * @return list<array<string, mixed>>
     */
    public function pendientes(User $usuario): array
    {
        return array_values($this->solicitudes->pendientes()->map(fn (SolicitudAnulacion $solicitud): array => [
            'id' => $solicitud->id,
            'motivo' => $solicitud->motivo,
            'solicitada_por' => $solicitud->solicitante->name,
            'solicitada_en' => $solicitud->created_at->toIso8601String(),
            'pago' => [
                'id' => $solicitud->pago->id,
                'numero_recibo' => $solicitud->pago->numero_recibo,
                'fecha_pago' => $solicitud->pago->fecha_pago->toDateString(),
                'vivienda' => $solicitud->pago->vivienda->codigo,
                'monto' => $solicitud->pago->monto,
                'cobrador' => $solicitud->pago->cobrador?->name,
            ],
            'puede_resolver' => $this->motivoParaNoResolver($usuario, $solicitud) === null,
            'motivo_no_resolver' => $this->motivoParaNoResolver($usuario, $solicitud),
        ])->all());
    }

    /**
     * Cantidad de solicitudes por aprobar, solo para quien puede aprobarlas
     */
    public function cantidadPendientes(User $usuario): int
    {
        return $this->permisos->puede($usuario, 'cobros', 'aprobar') ? $this->solicitudes->cantidadPendientes() : 0;
    }

    /**
     * Solo se pide anular un pago confirmado que no tenga otra solicitud pendiente
     */
    public function puedeSolicitar(User $usuario, Pago $pago): bool
    {
        return $pago->estado === Pago::CONFIRMADO
            && $this->permisos->puede($usuario, 'cobros', 'anular')
            && ! $this->solicitudes->tienePendiente($pago->id);
    }

    /**
     * Registra la solicitud; el pago sigue confirmado hasta que otro directivo la apruebe
     *
     * @throws ValidationException
     */
    public function solicitar(User $usuario, Pago $pago, string $motivo): SolicitudAnulacion
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $pago, $motivo) {
            $pago = $this->pagos->bloquearRegistro($pago->id) ?? $pago;

            if ($pago->estado !== Pago::CONFIRMADO) {
                throw ValidationException::withMessages(['motivo' => 'Solo se puede anular un pago confirmado.']);
            }

            if ($this->solicitudes->tienePendiente($pago->id)) {
                throw ValidationException::withMessages(['motivo' => 'Este pago ya tiene una solicitud de anulación pendiente.']);
            }

            $solicitud = $this->solicitudes->crear([
                'residencial_id' => $pago->residencial_id,
                'pago_id' => $pago->id,
                'solicitada_por' => $usuario->id,
                'motivo' => trim($motivo),
                'estado' => SolicitudAnulacion::PENDIENTE,
            ]);

            $this->auditoria->registrar($usuario, 'cobros', 'anulacion_solicitada', $pago, [
                'valor_nuevo' => ['numero_recibo' => $pago->numero_recibo, 'solicitud_id' => $solicitud->id],
                'motivo' => trim($motivo),
            ]);

            return $solicitud;
        });
    }

    /**
     * Aprueba la solicitud y anula el pago, conservando quién, cuándo y por qué
     *
     * @throws ValidationException
     */
    public function aprobar(User $usuario, SolicitudAnulacion $solicitud): Pago
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $solicitud) {
            [$solicitud, $pago] = $this->bloquearParaResolver($usuario, $solicitud);

            if ($pago->estado !== Pago::CONFIRMADO) {
                throw ValidationException::withMessages(['solicitud' => 'El pago ya no está confirmado.']);
            }

            $ahora = now();

            $this->pagos->actualizar($pago, [
                'estado' => Pago::ANULADO,
                'anulado_por' => $solicitud->solicitada_por,
                'anulacion_aprobada_por' => $usuario->id,
                'anulado_en' => $ahora,
                'motivo_anulacion' => $solicitud->motivo,
            ]);

            $this->solicitudes->actualizar($solicitud, [
                'estado' => SolicitudAnulacion::APROBADA,
                'resuelta_por' => $usuario->id,
                'resuelta_en' => $ahora,
            ]);

            $this->auditoria->registrar($usuario, 'cobros', 'pago_anulado', $pago, [
                'valor_anterior' => ['estado' => Pago::CONFIRMADO],
                'valor_nuevo' => [
                    'estado' => Pago::ANULADO,
                    'numero_recibo' => $pago->numero_recibo,
                    'monto' => $pago->monto,
                    'solicitud_id' => $solicitud->id,
                    'solicitada_por' => $solicitud->solicitada_por,
                ],
                'motivo' => $solicitud->motivo,
            ]);

            return $pago;
        });
    }

    /**
     * Rechaza la solicitud; el pago sigue confirmado
     *
     * @throws ValidationException
     */
    public function rechazar(User $usuario, SolicitudAnulacion $solicitud, string $motivo): SolicitudAnulacion
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $solicitud, $motivo) {
            [$solicitud, $pago] = $this->bloquearParaResolver($usuario, $solicitud);

            $this->solicitudes->actualizar($solicitud, [
                'estado' => SolicitudAnulacion::RECHAZADA,
                'resuelta_por' => $usuario->id,
                'resuelta_en' => now(),
                'motivo_rechazo' => trim($motivo),
            ]);

            $this->auditoria->registrar($usuario, 'cobros', 'anulacion_rechazada', $pago, [
                'valor_nuevo' => ['numero_recibo' => $pago->numero_recibo, 'solicitud_id' => $solicitud->id],
                'motivo' => trim($motivo),
            ]);

            return $solicitud;
        });
    }

    /**
     * Por qué el usuario no puede resolver la solicitud; null si puede
     */
    private function motivoParaNoResolver(User $usuario, SolicitudAnulacion $solicitud): ?string
    {
        return match (true) {
            $solicitud->estado !== SolicitudAnulacion::PENDIENTE => 'Esta solicitud ya fue resuelta.',
            ! $this->permisos->puede($usuario, 'cobros', 'aprobar') => 'No tienes permiso para aprobar anulaciones.',
            $solicitud->solicitada_por === $usuario->id => 'No puedes resolver tu propia solicitud. Otro directivo debe hacerlo.',
            $solicitud->pago->registrado_por === $usuario->id => 'No puedes resolver la anulación de un cobro que registraste.',
            default => null,
        };
    }

    /**
     * Reserva la solicitud y su pago y revisa que el usuario pueda resolverla
     *
     * @return array{SolicitudAnulacion, Pago}
     *
     * @throws ValidationException
     */
    private function bloquearParaResolver(User $usuario, SolicitudAnulacion $solicitud): array
    {
        $solicitud = $this->solicitudes->bloquear($solicitud->id) ?? $solicitud;
        $pago = $this->pagos->bloquearRegistro($solicitud->pago_id) ?? $solicitud->pago;
        $solicitud->setRelation('pago', $pago);

        $motivo = $this->motivoParaNoResolver($usuario, $solicitud);

        if ($motivo !== null) {
            throw ValidationException::withMessages(['solicitud' => $motivo]);
        }

        return [$solicitud, $pago];
    }
}
