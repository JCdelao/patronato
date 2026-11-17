<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Patronato\Persistencia\Models\Carnet;

class CarnetRepositorio extends Repositorio
{
    /**
     * Espacio del bloqueo de la numeración de carnets, distinto al de los recibos
     */
    private const ESPACIO_BLOQUEO = 19002;

    /**
     * Prefijo de los números de carnet
     */
    private const PREFIJO = 'C-';

    /**
     * Ordena en fila las emisiones del residencial hasta terminar la transacción
     */
    public function bloquearNumeracion(int $residencialId): void
    {
        // SQLite ya permite una sola escritura a la vez
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::ESPACIO_BLOQUEO, $residencialId]);
        }
    }

    /**
     * Siguiente número del residencial: C-0001, C-0002... incluidos los carnets viejos
     */
    public function siguienteNumero(int $residencialId): string
    {
        $ultimo = (int) Carnet::query()
            ->withoutGlobalScope('residencial')
            ->where('residencial_id', $residencialId)
            ->where('numero', 'like', self::PREFIJO.'%')
            ->max(DB::raw('CAST(SUBSTR(numero, 3) AS INTEGER)'));

        return self::PREFIJO.str_pad((string) ($ultimo + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Carnet que todavía pertenece al residente, reservado hasta terminar la transacción
     */
    public function bloquearVigenteDe(int $residenteId): ?Carnet
    {
        return Carnet::query()
            ->lockForUpdate()
            ->where('residente_id', $residenteId)
            ->whereIn('estado', Carnet::VIGENTES)
            ->first();
    }

    /**
     * Vuelve a leer el carnet reservándolo hasta terminar la transacción
     */
    public function bloquearRegistro(int $carnetId): ?Carnet
    {
        return Carnet::query()->lockForUpdate()->find($carnetId);
    }

    /**
     * Carnets activos de todos los residenciales cuya vigencia terminó antes de la fecha
     *
     * Lo usa la tarea diaria, que corre sin usuario.
     *
     * @return Collection<int, Carnet>
     */
    public function activosVencidosAntesDe(string $fecha): Collection
    {
        return Carnet::query()
            ->withoutGlobalScope('residencial')
            ->lockForUpdate()
            ->where('estado', Carnet::ACTIVO)
            ->whereNotNull('vigente_hasta')
            ->where('vigente_hasta', '<', $fecha)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Carnet
    {
        return Carnet::query()->create($datos);
    }
}
