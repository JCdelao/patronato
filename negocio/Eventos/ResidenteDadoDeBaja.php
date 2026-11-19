<?php

namespace Patronato\Negocio\Eventos;

use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\User;

/**
 * Un residente dejó de vivir en el residencial y quedó inactivo en el padrón
 */
final readonly class ResidenteDadoDeBaja
{
    public function __construct(
        public Residente $residente,
        public User $usuario,
    ) {}
}
