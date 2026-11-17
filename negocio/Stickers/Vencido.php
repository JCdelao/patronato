<?php

namespace Patronato\Negocio\Stickers;

/**
 * Terminó su vigencia; se renueva reemplazándolo
 */
final class Vencido extends EstadoSticker
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
