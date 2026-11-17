<?php

namespace Patronato\Negocio\Stickers;

/**
 * Sustituido por otro sticker; es final
 */
final class Reemplazado extends EstadoSticker
{
    public const NOMBRE = 'reemplazado';

    protected function transiciones(): array
    {
        return [];
    }
}
