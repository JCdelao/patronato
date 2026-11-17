<?php

namespace Patronato\Negocio\Credenciales;

/**
 * Retenido por un tiempo; se puede reactivar
 */
final class Suspendido extends EstadoCredencial
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
