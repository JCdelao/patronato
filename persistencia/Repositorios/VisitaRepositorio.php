<?php

namespace Patronato\Persistencia\Repositorios;

use Patronato\Persistencia\Models\Visita;

class VisitaRepositorio extends Repositorio
{
    /** @param array<string, mixed> $datos */
    public function registrarEntrada(array $datos): Visita
    {
        return Visita::query()->create($datos);
    }
}
