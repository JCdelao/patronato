<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\Caja;

class CajaRepositorio extends Repositorio
{
    /**
     * Caja abierta del cobrador en un día; con bloqueo la reserva hasta terminar la transacción
     */
    public function abiertaDelDia(int $residencialId, int $cobradorId, string $fecha, bool $bloquear = false): ?Caja
    {
        return Caja::query()
            ->where('residencial_id', $residencialId)
            ->where('cobrador_id', $cobradorId)
            ->where('estado', Caja::ABIERTA)
            ->whereDate('fecha', $fecha)
            ->when($bloquear, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * Indica si el cobrador tiene una caja abierta en otro residencial
     */
    public function tieneAbiertaFueraDe(int $residencialId, int $cobradorId): bool
    {
        return Caja::query()
            ->withoutGlobalScope('residencial')
            ->where('cobrador_id', $cobradorId)
            ->where('residencial_id', '<>', $residencialId)
            ->where('estado', Caja::ABIERTA)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Caja
    {
        return Caja::query()->create($datos);
    }

    /**
     * Vuelve a leer la caja reservándola hasta terminar la transacción
     */
    public function bloquear(int $cajaId): ?Caja
    {
        return Caja::query()->lockForUpdate()->find($cajaId);
    }

    /**
     * Cajas abiertas del residencial con su cobrador y sus cobros
     *
     * @return Collection<int, Caja>
     */
    public function abiertas(): Collection
    {
        return Caja::query()
            ->where('estado', Caja::ABIERTA)
            ->with(['cobrador', 'pagos'])
            ->orderBy('abierta_en')
            ->orderBy('id')
            ->get();
    }

    /**
     * Últimas cajas cerradas del residencial
     *
     * @return Collection<int, Caja>
     */
    public function cerradasRecientes(int $limite): Collection
    {
        return Caja::query()
            ->where('estado', Caja::CERRADA)
            ->with(['cobrador', 'cerradaPor', 'pagos'])
            ->latest('cerrada_en')
            ->latest('id')
            ->limit($limite)
            ->get();
    }

    /**
     * Usuarios que han tenido caja en el residencial, para filtrar los reportes
     *
     * @return list<array{id: int, nombre: string}>
     */
    public function cobradores(): array
    {
        $filas = Caja::query()
            ->join('users', 'users.id', '=', 'cajas.cobrador_id')
            ->distinct()
            ->orderBy('users.name')
            ->toBase()
            ->get(['cajas.cobrador_id', 'users.name']);

        $cobradores = [];

        foreach ($filas as $fila) {
            $cobradores[] = ['id' => (int) $fila->cobrador_id, 'nombre' => (string) $fila->name];
        }

        return $cobradores;
    }

    /**
     * Carga lo que muestra el cierre: cobrador, quién cerró y cobros con su vivienda
     */
    public function conDetalle(Caja $caja): Caja
    {
        return $caja->load(['cobrador', 'cerradaPor', 'pagos' => fn ($query) => $query->with('vivienda')->orderBy('id')]);
    }
}
