<?php

namespace Patronato\Negocio\Credenciales;

/**
 * Retirado definitivamente; es final
 */
final class DadoDeBaja extends EstadoCredencial
{
    public const NOMBRE = 'dado_de_baja';

    protected function transiciones(): array
    {
        return [];
    }
}
