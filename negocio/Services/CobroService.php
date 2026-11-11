<?php

namespace Patronato\Negocio\Services;

use Illuminate\Validation\ValidationException;
use Patronato\Negocio\Contratos\CargosNoIntegrados;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\PagoRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Cobros en efectivo casa por casa
 */
class CobroService
{
    public function __construct(
        private PagoRepositorio $pagos,
        private Transaccion $transaccion,
        private CajaService $cajas,
        private NumeracionReciboService $recibos,
        private AplicacionPagoService $aplicaciones,
        private ConfiguracionService $configuracion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Registra un pago en efectivo en la caja abierta del cobrador
     *
     * El pago, sus aplicaciones y la auditoría se guardan juntos o no se guarda nada.
     *
     * @param  array<int, int>  $cargoIds  meses que se pagan
     *
     * @throws ValidationException
     */
    public function registrarEfectivo(User $cobrador, int $viviendaId, string $monto, array $cargoIds): Pago
    {
        $residencialId = (int) $cobrador->residencial_id;

        return $this->transaccion->ejecutar(function () use ($cobrador, $residencialId, $viviendaId, $monto, $cargoIds) {
            // Un cobro a la vez por residencial: protege el recibo y los meses pendientes
            $this->recibos->bloquear($residencialId);

            $caja = $this->cajas->bloquearAbiertaDe($cobrador);

            if ($caja === null) {
                throw ValidationException::withMessages(['caja' => 'No tienes una caja abierta. Pide al tesorero que la abra.']);
            }

            try {
                $pendiente = $this->aplicaciones->pendienteDe($viviendaId, $cargoIds);
            } catch (CargosNoIntegrados $error) {
                throw ValidationException::withMessages(['cargos' => $error->getMessage()]);
            }

            $centavos = AplicacionPagoService::centavos($monto);

            if ($centavos > $pendiente) {
                throw ValidationException::withMessages(['monto' => 'El monto supera lo pendiente de los meses elegidos.']);
            }

            $esAbono = $centavos < $pendiente;

            if ($esAbono && ! $this->configuracion->permiteAbonoParcial($residencialId)) {
                throw ValidationException::withMessages(['monto' => 'El patronato no permite abonos parciales: el monto debe cubrir los meses elegidos.']);
            }

            // El efectivo queda confirmado al registrarse
            $pago = $this->pagos->crear([
                'residencial_id' => $residencialId,
                'vivienda_id' => $viviendaId,
                'forma_pago' => Pago::EFECTIVO,
                'estado' => Pago::CONFIRMADO,
                'origen' => 'sistema',
                'monto' => AplicacionPagoService::monto($centavos),
                'fecha_pago' => now()->toDateString(),
                'numero_recibo' => $this->recibos->siguiente($residencialId),
                'caja_id' => $caja->id,
                'registrado_por' => $cobrador->id,
            ]);

            $this->aplicaciones->aplicar($pago, $cargoIds);

            $this->auditoria->registrar($cobrador, 'cobros', 'pago_registrado', $pago, [
                'valor_nuevo' => [
                    'numero_recibo' => $pago->numero_recibo,
                    'vivienda_id' => $viviendaId,
                    'monto' => $pago->monto,
                    'meses' => array_values(array_unique($cargoIds)),
                    'abono_parcial' => $esAbono,
                    'caja_id' => $caja->id,
                ],
            ]);

            return $pago;
        });
    }
}
