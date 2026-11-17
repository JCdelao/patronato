<?php

namespace Patronato\Negocio\Stickers;

use InvalidArgumentException;

/**
 * Estado de un sticker: sabe qué acciones admite y a qué estado lleva cada una
 *
 * Cada estado es una clase; las fabrica EstadoSticker::de().
 */
abstract class EstadoSticker
{
    public const SUSPENDER = 'suspender';

    public const REACTIVAR = 'reactivar';

    public const VENCER = 'vencer';

    public const REEMPLAZAR = 'reemplazar';

    public const DAR_DE_BAJA = 'dar_de_baja';

    /**
     * Estado al que lleva cada acción admitida
     *
     * @return array<string, string>
     */
    abstract protected function transiciones(): array;

    /**
     * Crea el estado que corresponde al valor guardado
     */
    public static function de(string $estado): self
    {
        return match ($estado) {
            Activo::NOMBRE => new Activo,
            Suspendido::NOMBRE => new Suspendido,
            Vencido::NOMBRE => new Vencido,
            Reemplazado::NOMBRE => new Reemplazado,
            DadoDeBaja::NOMBRE => new DadoDeBaja,
            default => throw new InvalidArgumentException("Estado de sticker desconocido: {$estado}"),
        };
    }

    /**
     * Acciones que se pueden hacer con un sticker en este estado
     *
     * @return list<string>
     */
    public function acciones(): array
    {
        return array_keys($this->transiciones());
    }

    public function permite(string $accion): bool
    {
        return array_key_exists($accion, $this->transiciones());
    }

    /**
     * Estado que resulta de la acción, o null si este estado no la admite
     */
    public function siguiente(string $accion): ?string
    {
        return $this->transiciones()[$accion] ?? null;
    }
}
