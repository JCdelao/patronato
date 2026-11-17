<?php

namespace Patronato\Negocio\Oyentes;

use Patronato\Negocio\Eventos\VehiculoDadoDeBaja;
use Patronato\Negocio\Services\StickerService;

/**
 * Un vehículo dado de baja no conserva su sticker
 */
class DarDeBajaStickerDelVehiculo
{
    public function __construct(private StickerService $stickers) {}

    public function handle(VehiculoDadoDeBaja $evento): void
    {
        $this->stickers->darDeBajaDeVehiculo($evento->usuario, $evento->vehiculo, $evento->motivo);
    }
}
