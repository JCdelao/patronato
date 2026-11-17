<?php

namespace Patronato\Negocio\Credenciales;

/**
 * Sustituido por otra credencial; es final
 */
final class Reemplazado extends EstadoCredencial
{
    public const NOMBRE = 'reemplazado';

    protected function transiciones(): array
    {
        return [];
    }
}
