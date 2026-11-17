<?php

namespace Patronato\Negocio\Stickers;

/**
 * Retirado definitivamente; es final
 */
final class DadoDeBaja extends EstadoSticker
{
    public const NOMBRE = 'dado_de_baja';

    protected function transiciones(): array
    {
        return [];
    }
}
