<?php

namespace Patronato\Negocio\Services;

use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\CajaRepositorio;

/**
 * Cajas de los cobradores
 *
 * La caja se abre sola con el primer cobro y la cierra el tesorero (CierreCajaService).
 */
class CajaService
{
    public function __construct(
        private CajaRepositorio $cajas,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Caja abierta del cobrador, si tiene una
     */
    public function abiertaDe(User $cobrador): ?Caja
    {
        return $this->cajas->abiertaDe((int) $cobrador->residencial_id, $cobrador->id);
    }

    /**
     * Caja abierta del cobrador bloqueada hasta terminar la transacción
     *
     * El cierre de caja bloquea la misma fila para no cruzarse con un cobro.
     */
    public function bloquearAbiertaDe(User $cobrador): ?Caja
    {
        return $this->cajas->abiertaDe((int) $cobrador->residencial_id, $cobrador->id, bloquear: true);
    }

    /**
     * Abre una caja nueva para el cobrador y deja la apertura auditada
     *
     * Se llama dentro de la transacción del cobro, con el residencial ya bloqueado.
     *
     * @throws ValidationException si el cobrador tiene una caja abierta en otro residencial
     */
    public function abrir(User $cobrador): Caja
    {
        if ($this->cajas->tieneAbiertaFueraDe((int) $cobrador->residencial_id, $cobrador->id)) {
            throw ValidationException::withMessages(['caja' => 'Tienes una caja abierta en otro residencial. Pide que la cierren antes de cobrar aquí.']);
        }

        $caja = $this->cajas->crear([
            'residencial_id' => $cobrador->residencial_id,
            'cobrador_id' => $cobrador->id,
            'estado' => Caja::ABIERTA,
            'abierta_en' => now(),
        ]);

        $this->auditoria->registrar($cobrador, 'cajas', 'caja_abierta', $caja, [
            'valor_nuevo' => ['estado' => Caja::ABIERTA, 'cobrador_id' => $cobrador->id],
        ]);

        return $caja;
    }
}
