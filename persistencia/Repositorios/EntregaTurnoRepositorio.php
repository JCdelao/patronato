<?php

namespace Patronato\Persistencia\Repositorios;

use Patronato\Persistencia\Models\EntregaTurno;

class EntregaTurnoRepositorio extends Repositorio
{
    /**
     * Entrega más reciente que el guardia todavía no confirma, con quién la hizo y su turno
     */
    public function pendienteDe(int $guardiaId): ?EntregaTurno
    {
        return EntregaTurno::query()
            ->where('guardia_recibe_id', $guardiaId)
            ->whereNull('recibido_en')
            ->with(['guardiaEntrega:id,nombre', 'turno'])
            ->latest('entregado_en')
            ->first();
    }

    /**
     * Vuelve a leer la entrega reservándola hasta terminar la transacción
     */
    public function bloquearRegistro(int $entregaId): ?EntregaTurno
    {
        return EntregaTurno::query()->lockForUpdate()->find($entregaId);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): EntregaTurno
    {
        return EntregaTurno::query()->create($datos);
    }
}
