<?php

namespace Patronato\Negocio\Eventos;

use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vehiculo;

/**
 * Un vehículo dejó de estar activo en el residencial
 */
final readonly class VehiculoDadoDeBaja
{
    public function __construct(
        public Vehiculo $vehiculo,
        public User $usuario,
        public string $motivo,
    ) {}
}
