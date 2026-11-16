<?php

namespace Patronato\Negocio\Eventos;

use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\User;

/**
 * Se aprobó la anulación de un pago confirmado
 */
final readonly class PagoAnulado
{
    public function __construct(
        public Pago $pago,
        public User $usuario,
        public string $motivo,
    ) {}
}
