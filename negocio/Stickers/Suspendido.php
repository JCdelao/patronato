<?php

namespace Patronato\Negocio\Stickers;

/**
 * Retenido por un tiempo; se puede reactivar
 */
final class Suspendido extends EstadoSticker
{
    public const NOMBRE = 'suspendido';

    protected function transiciones(): array
    {
        return [
            self::REACTIVAR => Activo::NOMBRE,
            self::REEMPLAZAR => Reemplazado::NOMBRE,
            self::DAR_DE_BAJA => DadoDeBaja::NOMBRE,
        ];
    }
}
