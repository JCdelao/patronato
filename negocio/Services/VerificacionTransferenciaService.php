<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Patronato\Negocio\Contratos\CargosNoIntegrados;
use Patronato\Negocio\Contratos\CargosPendientes;
use Patronato\Negocio\Eventos\PagoConfirmado;
use Patronato\Negocio\Notificaciones\TransferenciaConfirmada;
use Patronato\Negocio\Notificaciones\TransferenciaRechazada;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\PagoRepositorio;
use Patronato\Persistencia\Repositorios\VinculoRepositorio;
use Patronato\Persistencia\Transaccion;
use Throwable;

/**
 * Verificación de transferencias (HU-26)
 *
 * Reglas:
 * - Tesorería confirma o rechaza las transferencias pendientes después de revisar la cuenta bancaria.
 * - Al confirmarse, el pago recibe su número de recibo, se aplica a los meses elegidos con las
 *   mismas reglas del cobro en efectivo y entra como ingreso. No entra a ninguna caja.
 * - Con el doble control activado, quien registró la transferencia no puede confirmarla.
 * - El rechazo exige un motivo y libera la referencia bancaria; el comprobante se conserva.
 * - Se guarda quién la verificó y cuándo, y el residente recibe un correo con el resultado.
 *   El correo sale después de guardar: si falla, el error queda registrado y la verificación se conserva.
 */
class VerificacionTransferenciaService
{
    public const MOTIVO_MINIMO = 10;

    /**
     * Zona horaria para contar los días sin verificar
     */
    private const ZONA_LOCAL = 'America/Tegucigalpa';

    private const YA_VERIFICADA = 'Esta transferencia ya fue verificada.';

    public function __construct(
        private PagoRepositorio $pagos,
        private Transaccion $transaccion,
        private NumeracionReciboService $recibos,
        private AplicacionPagoService $aplicaciones,
        private CargosPendientes $cargos,
        private ConfiguracionService $configuracion,
        private ComprobanteService $comprobantes,
        private VinculoRepositorio $vinculos,
        private AuditoriaService $auditoria,
        private PermisoService $permisos,
        private Dispatcher $eventos,
    ) {}

    /**
     * Bandeja de transferencias por verificar, de la más antigua a la más reciente
     *
     * @return list<array{id: int, vivienda: string, monto: string, fecha_pago: string, banco: string|null, numero_referencia: string|null, origen: string, registrado_por: string|null, registrado_en: string, dias_sin_verificar: int, motivo_no_confirmar: string|null}>
     */
    public function pendientes(User $usuario): array
    {
        return array_values($this->pagos->porVerificar()->map(fn (Pago $pago): array => [
            'id' => $pago->id,
            'vivienda' => $pago->vivienda->codigo,
            'monto' => $pago->monto,
            'fecha_pago' => $pago->fecha_pago->toDateString(),
            'banco' => $pago->banco,
            'numero_referencia' => $pago->numero_referencia,
            'origen' => $pago->origen,
            'registrado_por' => $pago->cobrador?->name,
            'registrado_en' => $pago->created_at->toIso8601String(),
            'dias_sin_verificar' => self::diasSinVerificar($pago),
            'motivo_no_confirmar' => $this->motivoParaNoConfirmar($usuario, $pago),
        ])->all());
    }

    /**
     * Cantidad de transferencias por verificar, solo para quien puede verificarlas
     */
    public function cantidadPendientes(User $usuario): int
    {
        return $this->permisos->puede($usuario, 'transferencias', 'verificar') ? $this->pagos->cantidadPorVerificar() : 0;
    }

    /**
     * Días completos, en la hora de Honduras, desde que se registró la transferencia
     */
    public static function diasSinVerificar(Pago $pago, ?CarbonImmutable $ahora = null): int
    {
        $registro = CarbonImmutable::parse($pago->created_at)->setTimezone(self::ZONA_LOCAL)->startOfDay();
        $hoy = ($ahora ?? CarbonImmutable::now())->setTimezone(self::ZONA_LOCAL)->startOfDay();

        return max(0, (int) $registro->diffInDays($hoy));
    }

    /**
     * Datos para verificar una transferencia: el pago, sus meses pendientes y lo que se puede hacer
     *
     * Los meses sugeridos son los más antiguos hasta cubrir el monto de la transferencia.
     * Si el residencial acepta saldo a favor y el monto supera todo lo pendiente, no se
     * sugiere ninguno: sin meses elegidos, el excedente queda como saldo a favor.
     *
     * @return array<string, mixed>
     */
    public function detalle(User $usuario, Pago $pago): array
    {
        $pago = $this->pagos->conVerificacion($pago);
        $porVerificar = $pago->esPorVerificar();
        $pendientes = null;
        $avisoIntegracion = null;
        $permiteSaldoFavor = $this->configuracion->permiteSaldoFavor($pago->residencial_id);

        if ($porVerificar) {
            try {
                $pendientes = $this->cargos->pendientesDeVivienda($pago->vivienda_id)->values()->all();
            } catch (CargosNoIntegrados $error) {
                $avisoIntegracion = $error->getMessage();
            }
        }

        return [
            'id' => $pago->id,
            'vivienda' => $pago->vivienda->codigo,
            'monto' => $pago->monto,
            'fecha_pago' => $pago->fecha_pago->toDateString(),
            'banco' => $pago->banco,
            'numero_referencia' => $pago->numero_referencia,
            'cuenta_receptora' => $pago->cuenta_receptora,
            'estado' => $pago->estado,
            'origen' => $pago->origen,
            'registrado_por' => $pago->cobrador?->name,
            'registrado_en' => $pago->created_at->toIso8601String(),
            'dias_sin_verificar' => $porVerificar ? self::diasSinVerificar($pago) : null,
            'tiene_comprobante' => $pago->comprobante_path !== null,
            'numero_recibo' => $pago->numero_recibo,
            'verificado_por' => $pago->verificador?->name,
            'verificado_en' => $pago->verificado_en?->toIso8601String(),
            'motivo_rechazo' => $pago->motivo_rechazo,
            'por_verificar' => $porVerificar,
            'motivo_no_confirmar' => $porVerificar ? $this->motivoParaNoConfirmar($usuario, $pago) : null,
            'permite_abono_parcial' => $this->configuracion->permiteAbonoParcial($pago->residencial_id),
            'permite_saldo_favor' => $permiteSaldoFavor,
            'pendientes' => $pendientes,
            'sugeridos' => $pendientes === null ? [] : self::sugeridos($pendientes, $pago->monto, $permiteSaldoFavor),
            'aviso_integracion' => $avisoIntegracion,
        ];
    }

    /**
     * Confirma la transferencia y la aplica a los meses elegidos
     *
     * Sin meses elegidos se aplica a los pendientes del más antiguo al más reciente.
     * El recibo, el estado, las aplicaciones, el ingreso y la auditoría se guardan juntos o no se guarda nada.
     *
     * @param  array<int, int>  $cargoIds  vacío para aplicarla a los más antiguos
     *
     * @throws ValidationException
     */
    public function confirmar(User $usuario, Pago $pago, array $cargoIds): Pago
    {
        $residencialId = (int) $usuario->residencial_id;

        $pago = $this->transaccion->ejecutar(function () use ($usuario, $residencialId, $pago, $cargoIds) {
            // Un cobro a la vez por residencial: protege el recibo y los meses pendientes
            $this->recibos->bloquear($residencialId);
            $pago = $this->pagos->bloquearRegistro($pago->id) ?? $pago;

            if (! $pago->esPorVerificar()) {
                throw ValidationException::withMessages(['pago' => self::YA_VERIFICADA]);
            }

            $motivo = $this->motivoParaNoConfirmar($usuario, $pago);

            if ($motivo !== null) {
                throw ValidationException::withMessages(['pago' => $motivo]);
            }

            try {
                $pendiente = $this->aplicaciones->pendienteDe($pago->vivienda_id, $cargoIds);
            } catch (CargosNoIntegrados $error) {
                throw ValidationException::withMessages(['cargos' => $error->getMessage()]);
            }

            $centavos = AplicacionPagoService::centavos($pago->monto);
            $sinMeses = $cargoIds === [];

            // Solo una transferencia sin meses puede dejar saldo a favor, si el residencial lo acepta
            $admiteExcedente = $sinMeses && $this->configuracion->permiteSaldoFavor($residencialId);

            if ($centavos > $pendiente && ! $admiteExcedente) {
                throw ValidationException::withMessages(['cargos' => $sinMeses
                    ? 'El monto de la transferencia supera lo pendiente de la vivienda.'
                    : 'El monto de la transferencia supera lo pendiente de los meses elegidos. Elige más meses.']);
            }

            $esAbono = $centavos < $pendiente;

            if ($esAbono && ! $this->configuracion->permiteAbonoParcial($residencialId)) {
                throw ValidationException::withMessages(['cargos' => $sinMeses
                    ? 'El patronato no permite abonos parciales: sin meses elegidos, el monto de la transferencia debe cubrir todo lo pendiente de la vivienda.'
                    : 'El patronato no permite abonos parciales: el monto de la transferencia debe cubrir los meses elegidos.']);
            }

            $estadoAnterior = $pago->estado;

            $this->pagos->actualizar($pago, [
                'estado' => Pago::CONFIRMADO,
                'numero_recibo' => $this->recibos->siguiente($residencialId),
                'verificado_por' => $usuario->id,
                'verificado_en' => now(),
            ]);

            $aplicados = $this->aplicaciones->aplicar($pago, $cargoIds, $admiteExcedente);

            $this->auditoria->registrar($usuario, 'transferencias', 'transferencia_confirmada', $pago, [
                'valor_anterior' => ['estado' => $estadoAnterior],
                'valor_nuevo' => [
                    'estado' => Pago::CONFIRMADO,
                    'numero_recibo' => $pago->numero_recibo,
                    'monto' => $pago->monto,
                    // Sin meses elegidos se guardan los meses a los que se aplicó
                    'meses' => $sinMeses ? $aplicados : array_values(array_unique($cargoIds)),
                    'abono_parcial' => $esAbono,
                    ...($sinMeses ? ['saldo_a_favor' => AplicacionPagoService::monto(max(0, $centavos - $pendiente))] : []),
                ],
            ]);

            // Tesorería lo registra como ingreso dentro de esta misma transacción
            $this->eventos->dispatch(new PagoConfirmado($pago, $usuario));

            return $pago;
        });

        $this->avisar($pago, function (Pago $pago, array $correos): void {
            $pago = $this->pagos->conDetalle($pago);

            Notification::route('mail', $correos)->notify(new TransferenciaConfirmada(
                $pago->residencial->nombre,
                $pago->vivienda->codigo,
                $pago->monto,
                $pago->fecha_pago->toDateString(),
                (string) $pago->banco,
                (string) $pago->numero_referencia,
                (string) $pago->numero_recibo,
                $this->comprobantes->pdf($pago),
                $this->comprobantes->nombreArchivo($pago),
            ));
        });

        return $pago;
    }

    /**
     * Rechaza la transferencia con un motivo; no se aplica y libera la referencia bancaria
     *
     * @throws ValidationException
     */
    public function rechazar(User $usuario, Pago $pago, string $motivo): Pago
    {
        $motivo = trim($motivo);

        $pago = $this->transaccion->ejecutar(function () use ($usuario, $pago, $motivo) {
            $pago = $this->pagos->bloquearRegistro($pago->id) ?? $pago;

            if (! $pago->esPorVerificar()) {
                throw ValidationException::withMessages(['pago' => self::YA_VERIFICADA]);
            }

            $estadoAnterior = $pago->estado;

            $this->pagos->actualizar($pago, [
                'estado' => Pago::RECHAZADO,
                'motivo_rechazo' => $motivo,
                'verificado_por' => $usuario->id,
                'verificado_en' => now(),
            ]);

            $this->auditoria->registrar($usuario, 'transferencias', 'transferencia_rechazada', $pago, [
                'valor_anterior' => ['estado' => $estadoAnterior],
                'valor_nuevo' => [
                    'estado' => Pago::RECHAZADO,
                    'monto' => $pago->monto,
                    'banco' => $pago->banco,
                    'numero_referencia' => $pago->numero_referencia,
                ],
                'motivo' => $motivo,
            ]);

            return $pago;
        });

        $this->avisar($pago, function (Pago $pago, array $correos) use ($motivo): void {
            $pago = $this->pagos->conDetalle($pago);

            Notification::route('mail', $correos)->notify(new TransferenciaRechazada(
                $pago->residencial->nombre,
                $pago->vivienda->codigo,
                $pago->monto,
                $pago->fecha_pago->toDateString(),
                (string) $pago->banco,
                (string) $pago->numero_referencia,
                $motivo,
            ));
        });

        return $pago;
    }

    /**
     * Por qué el usuario no puede confirmar la transferencia; null si puede
     */
    public function motivoParaNoConfirmar(User $usuario, Pago $pago): ?string
    {
        return $pago->registrado_por === $usuario->id && $this->configuracion->dobleControlTransferencias($pago->residencial_id)
            ? 'El patronato usa doble control: no puedes confirmar una transferencia que registraste. Otra persona de tesorería debe hacerlo.'
            : null;
    }

    /**
     * Correos que reciben el resultado: los residentes actuales de la vivienda y,
     * si la reportó desde el portal, la cuenta que la reportó
     *
     * @return list<string>
     */
    public function destinatarios(Pago $pago): array
    {
        $correos = $this->vinculos->correosVigentesDe($pago->vivienda_id);
        $reportante = $pago->origen === Pago::PORTAL ? $pago->cobrador?->email : null;

        if ($reportante !== null && $reportante !== '') {
            $correos[] = $reportante;
        }

        $unicos = [];

        foreach ($correos as $correo) {
            $unicos[mb_strtolower(trim($correo))] ??= trim($correo);
        }

        return array_values($unicos);
    }

    /**
     * Meses más antiguos que hacen falta para cubrir el monto
     *
     * Ninguno si el monto supera todo lo pendiente y el residencial acepta saldo a favor.
     *
     * @param  array<int, array{cargo_id: int, periodo: string, concepto: string, monto: string, pendiente: string}>  $pendientes  ordenados del más antiguo al más reciente
     * @return list<int>
     */
    private static function sugeridos(array $pendientes, string $monto, bool $permiteSaldoFavor): array
    {
        $restante = AplicacionPagoService::centavos($monto);
        $total = array_sum(array_map(fn (array $cargo): int => AplicacionPagoService::centavos($cargo['pendiente']), $pendientes));
        $ids = [];

        if ($permiteSaldoFavor && $restante > $total) {
            return [];
        }

        foreach ($pendientes as $cargo) {
            if ($restante <= 0) {
                break;
            }

            $ids[] = $cargo['cargo_id'];
            $restante -= AplicacionPagoService::centavos($cargo['pendiente']);
        }

        return $ids;
    }

    /**
     * Envía el aviso al residente sin deshacer la verificación si el correo falla
     *
     * @param  callable(Pago, list<string>): void  $enviar
     */
    private function avisar(Pago $pago, callable $enviar): void
    {
        try {
            $this->pagos->conVerificacion($pago);
            $correos = $this->destinatarios($pago);

            if ($correos !== []) {
                $enviar($pago, $correos);
            }
        } catch (Throwable $error) {
            report($error);
        }
    }
}
