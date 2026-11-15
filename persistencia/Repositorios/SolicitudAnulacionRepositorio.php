<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\SolicitudAnulacion;

class SolicitudAnulacionRepositorio extends Repositorio
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): SolicitudAnulacion
    {
        return SolicitudAnulacion::query()->create($datos);
    }

    /**
     * Indica si el pago tiene una solicitud esperando aprobación
     */
    public function tienePendiente(int $pagoId): bool
    {
        return SolicitudAnulacion::query()
            ->where('pago_id', $pagoId)
            ->where('estado', SolicitudAnulacion::PENDIENTE)
            ->exists();
    }

    /**
     * Vuelve a leer la solicitud reservándola hasta terminar la transacción
     */
    public function bloquear(int $solicitudId): ?SolicitudAnulacion
    {
        return SolicitudAnulacion::query()->lockForUpdate()->find($solicitudId);
    }

    /**
     * Solicitudes pendientes del residencial, de la más antigua a la más reciente
     *
     * @return Collection<int, SolicitudAnulacion>
     */
    public function pendientes(): Collection
    {
        return SolicitudAnulacion::query()
            ->where('estado', SolicitudAnulacion::PENDIENTE)
            ->with(['solicitante', 'pago.vivienda', 'pago.cobrador'])
            ->oldest()
            ->oldest('id')
            ->get();
    }

    /**
     * Cantidad de solicitudes pendientes del residencial, para el menú
     */
    public function cantidadPendientes(): int
    {
        return SolicitudAnulacion::query()->where('estado', SolicitudAnulacion::PENDIENTE)->count();
    }
}
