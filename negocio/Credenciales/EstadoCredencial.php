<?php

namespace Patronato\Negocio\Credenciales;

use InvalidArgumentException;

/**
 * Estado de una credencial (sticker o carnet): sabe qué acciones admite y a qué estado lleva cada una
 *
 * Cada estado es una clase; las fabrica EstadoCredencial::de().
 */
abstract class EstadoCredencial
{
    public const SUSPENDER = 'suspender';

    public const REACTIVAR = 'reactivar';

    public const VENCER = 'vencer';

    public const REEMPLAZAR = 'reemplazar';

    public const DAR_DE_BAJA = 'dar_de_baja';

    /**
     * Acciones que cambian el estado sin crear otra credencial
     */
    public const CAMBIOS_DE_ESTADO = [self::SUSPENDER, self::REACTIVAR, self::VENCER];

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
            default => throw new InvalidArgumentException("Estado de credencial desconocido: {$estado}"),
        };
    }

    /**
     * Acciones que se pueden hacer con una credencial en este estado
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
