<?php

namespace Patronato\Negocio\Eventos;

use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\User;

// Patrón Observer (comportamiento): evento que avisa que un pago quedó confirmado
/**
 * Un pago quedó confirmado: el dinero ya entró al patronato
 */
final readonly class PagoConfirmado
{
    public function __construct(
        public Pago $pago,
        public User $usuario,
    ) {}
}
