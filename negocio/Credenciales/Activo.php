<?php

namespace Patronato\Negocio\Credenciales;

/**
 * Pegado y válido para entrar
 */
final class Activo extends EstadoCredencial
{
    public const NOMBRE = 'activo';

    protected function transiciones(): array
    {
        return [
            self::SUSPENDER => Suspendido::NOMBRE,
            self::VENCER => Vencido::NOMBRE,
            self::REEMPLAZAR => Reemplazado::NOMBRE,
            self::DAR_DE_BAJA => DadoDeBaja::NOMBRE,
        ];
    }
}
