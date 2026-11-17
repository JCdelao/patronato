<?php

namespace Patronato\Negocio\Credenciales;

/**
 * Terminó su vigencia; se renueva reemplazándolo
 */
final class Vencido extends EstadoCredencial
{
    public const NOMBRE = 'vencido';

    protected function transiciones(): array
    {
        return [
            self::REEMPLAZAR => Reemplazado::NOMBRE,
            self::DAR_DE_BAJA => DadoDeBaja::NOMBRE,
        ];
    }
}
