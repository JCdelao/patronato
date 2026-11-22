<?php

namespace Patronato\Negocio\Eventos;

use Patronato\Persistencia\Models\User;

// Patrón Observer (comportamiento): evento que avisa que terminó una generación de cargos
/**
 * Se generaron los cargos de un período del residencial, aunque no se haya creado ninguno nuevo
 *
 * Se lanza dentro de la transacción de la generación. Sin usuario, la generó el comando programado.
 */
final readonly class CargosGenerados
{
    public function __construct(
        public int $residencialId,
        public string $periodo,
        public ?User $usuario,
    ) {}
}
