<?php

namespace Patronato\Negocio\Services;

use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\CajaRepositorio;

/**
 * Cajas de los cobradores
 *
 * Reglas:
 * - Cada caja dura un día, en la hora de Honduras.
 * - La caja del día se abre sola con el primer cobro; nadie tiene que abrirla.
 * - Una caja de un día anterior ya no recibe cobros y espera a que alguien con
 *   el permiso de cierre de caja la cierre (CierreCajaService).
 */
class CajaService
{
    public function __construct(
        private CajaRepositorio $cajas,
        private PeriodoService $periodos,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Caja del día del cobrador, si ya cobró hoy
     */
    public function delDia(User $cobrador): ?Caja
    {
        return $this->cajas->abiertaDelDia((int) $cobrador->residencial_id, $cobrador->id, $this->periodos->hoy()->toDateString());
    }

    /**
     * Caja del día del cobrador bloqueada hasta terminar la transacción; si no tiene, la abre
     *
     * Se llama dentro de la transacción del cobro, con el residencial ya bloqueado.
     * El cierre de caja bloquea la misma fila para no cruzarse con un cobro.
     *
     * @throws ValidationException si el cobrador tiene una caja abierta en otro residencial
     */
    public function delDiaParaCobrar(User $cobrador): Caja
    {
        $residencialId = (int) $cobrador->residencial_id;
        $hoy = $this->periodos->hoy()->toDateString();

        $caja = $this->cajas->abiertaDelDia($residencialId, $cobrador->id, $hoy, bloquear: true);

        if ($caja !== null) {
            return $caja;
        }

        if ($this->cajas->tieneAbiertaFueraDe($residencialId, $cobrador->id)) {
            throw ValidationException::withMessages(['caja' => 'Tienes una caja abierta en otro residencial. Pide que la cierren antes de cobrar aquí.']);
        }

        $caja = $this->cajas->crear([
            'residencial_id' => $residencialId,
            'cobrador_id' => $cobrador->id,
            'estado' => Caja::ABIERTA,
            'fecha' => $hoy,
            'abierta_en' => now(),
        ]);

        $this->auditoria->registrar($cobrador, 'cajas', 'caja_abierta', $caja, [
            'valor_nuevo' => ['estado' => Caja::ABIERTA, 'cobrador_id' => $cobrador->id, 'fecha' => $hoy],
        ]);

        return $caja;
    }
}
