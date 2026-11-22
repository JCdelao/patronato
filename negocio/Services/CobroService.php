<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Validation\ValidationException;
use Patronato\Negocio\Contratos\CargosNoIntegrados;
use Patronato\Negocio\Eventos\PagoConfirmado;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\PagoRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Cobros en efectivo casa por casa
 */
class CobroService
{
    /**
     * Zona horaria de la fecha del pago
     */
    private const ZONA_LOCAL = 'America/Tegucigalpa';

    public function __construct(
        private PagoRepositorio $pagos,
        private Transaccion $transaccion,
        private CajaService $cajas,
        private NumeracionReciboService $recibos,
        private AplicacionPagoService $aplicaciones,
        private ConfiguracionService $configuracion,
        private AuditoriaService $auditoria,
        private Dispatcher $eventos,
    ) {}

    // Patrón Facade (estructural): un solo método coordina la caja, el recibo, el pago, sus meses y la auditoría
    /**
     * Registra un pago en efectivo en la caja del día del cobrador
     *
     * Si el cobrador no tiene caja abierta hoy, el primer cobro le abre una.
     * La caja, el pago, sus aplicaciones y la auditoría se guardan juntos o no se guarda nada.
     *
     * Sin meses elegidos, el pago se aplica a los pendientes del más antiguo al más reciente.
     *
     * @param  array<int, int>  $cargoIds  meses que se pagan; vacío para aplicarlo a los más antiguos
     *
     * @throws ValidationException
     */
    public function registrarEfectivo(User $cobrador, int $viviendaId, string $monto, array $cargoIds): Pago
    {
        $residencialId = (int) $cobrador->residencial_id;

        return $this->transaccion->ejecutar(function () use ($cobrador, $residencialId, $viviendaId, $monto, $cargoIds) {
            // Un cobro a la vez por residencial: protege el recibo y los meses pendientes
            $this->recibos->bloquear($residencialId);

            try {
                $pendiente = $this->aplicaciones->pendienteDe($viviendaId, $cargoIds);
            } catch (CargosNoIntegrados $error) {
                throw ValidationException::withMessages(['cargos' => $error->getMessage()]);
            }

            $centavos = AplicacionPagoService::centavos($monto);
            $sinMeses = $cargoIds === [];

            // Solo un pago sin meses puede dejar saldo a favor, si el residencial lo acepta
            $admiteExcedente = $sinMeses && $this->configuracion->permiteSaldoFavor($residencialId);

            if ($centavos > $pendiente && ! $admiteExcedente) {
                throw ValidationException::withMessages(['monto' => $sinMeses
                    ? 'El monto supera lo pendiente de la vivienda.'
                    : 'El monto supera lo pendiente de los meses elegidos.']);
            }

            $esAbono = $centavos < $pendiente;

            if ($esAbono && ! $this->configuracion->permiteAbonoParcial($residencialId)) {
                throw ValidationException::withMessages(['monto' => $sinMeses
                    ? 'El patronato no permite abonos parciales: sin meses elegidos, el monto debe cubrir todo lo pendiente de la vivienda.'
                    : 'El patronato no permite abonos parciales: el monto debe cubrir los meses elegidos.']);
            }

            // El primer cobro del día abre la caja; el bloqueo del residencial evita abrir dos
            $caja = $this->cajas->delDiaParaCobrar($cobrador);

            // El efectivo queda confirmado al registrarse
            $pago = $this->pagos->crear([
                'residencial_id' => $residencialId,
                'vivienda_id' => $viviendaId,
                'forma_pago' => Pago::EFECTIVO,
                'estado' => Pago::CONFIRMADO,
                'origen' => 'sistema',
                'monto' => AplicacionPagoService::monto($centavos),
                // La fecha es la del día en Honduras, no la de UTC
                'fecha_pago' => CarbonImmutable::now(self::ZONA_LOCAL)->toDateString(),
                'numero_recibo' => $this->recibos->siguiente($residencialId),
                'caja_id' => $caja->id,
                'registrado_por' => $cobrador->id,
            ]);

            $aplicados = $this->aplicaciones->aplicar($pago, $cargoIds, $admiteExcedente);

            $this->auditoria->registrar($cobrador, 'cobros', 'pago_registrado', $pago, [
                'valor_nuevo' => [
                    'numero_recibo' => $pago->numero_recibo,
                    'vivienda_id' => $viviendaId,
                    'monto' => $pago->monto,
                    // Sin meses elegidos se guardan los meses a los que se aplicó
                    'meses' => $sinMeses ? $aplicados : array_values(array_unique($cargoIds)),
                    'abono_parcial' => $esAbono,
                    'caja_id' => $caja->id,
                    ...($sinMeses ? ['saldo_a_favor' => AplicacionPagoService::monto(max(0, $centavos - $pendiente))] : []),
                ],
            ]);

            // Patrón Observer (comportamiento): avisa del pago sin conocer a quién lo escucha
            // Tesorería lo registra como ingreso dentro de esta misma transacción
            $this->eventos->dispatch(new PagoConfirmado($pago, $cobrador));

            return $pago;
        });
    }
}
