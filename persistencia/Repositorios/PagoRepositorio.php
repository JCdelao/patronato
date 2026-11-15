<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\PagoAplicacion;

class PagoRepositorio
{
    // Identifica los bloqueos de pagos entre los bloqueos de PostgreSQL
    private const ESPACIO_BLOQUEO = 19001;

    /**
     * Mayor número de recibo generado por el sistema en el residencial
     *
     * Los pagos migrados no cuentan, porque su recibo puede tener cualquier forma.
     */
    public function ultimoNumeroRecibo(int $residencialId): int
    {
        return (int) Pago::query()
            ->withoutGlobalScope('residencial')
            ->where('residencial_id', $residencialId)
            ->where('origen', '<>', 'migracion')
            ->whereNotNull('numero_recibo')
            ->max(DB::raw('CAST(numero_recibo AS BIGINT)'));
    }

    /**
     * Ordena en fila los registros de pagos del residencial hasta terminar la transacción
     */
    public function bloquear(int $residencialId): void
    {
        // SQLite ya permite una sola escritura a la vez
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::ESPACIO_BLOQUEO, $residencialId]);
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Pago
    {
        return Pago::query()->create($datos);
    }

    /**
     * Guarda qué parte del pago cubre un cargo
     *
     * @param  array<string, mixed>  $datos
     */
    public function aplicar(array $datos): PagoAplicacion
    {
        return PagoAplicacion::query()->create($datos);
    }

    /**
     * Carga lo que muestra el comprobante: residencial, vivienda, cobrador y períodos
     *
     * Las relaciones se cargan sin el filtro de residencial porque el pago ya fue
     * autorizado y las llaves foráneas obligan a que sean del mismo residencial.
     * Así el enlace firmado funciona aunque haya una sesión de otro residencial.
     */
    public function conDetalle(Pago $pago): Pago
    {
        $sinFiltro = fn (Relation $relacion) => $relacion->withoutGlobalScope('residencial');

        return $pago->loadMissing([
            'residencial',
            'cobrador',
            'vivienda' => $sinFiltro,
            'aplicaciones' => $sinFiltro,
            'aplicaciones.cargo' => $sinFiltro,
            'aplicaciones.cargo.concepto' => $sinFiltro,
        ]);
    }

    /**
     * Cambia los datos de un pago; los de una caja cerrada solo admiten una anulación
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Pago $pago, array $datos): void
    {
        $pago->update($datos);
    }

    /**
     * Pagos del residencial, del más reciente al más antiguo
     *
     * Busca por número de recibo exacto o por código de vivienda.
     *
     * @return LengthAwarePaginator<int, Pago>
     */
    public function paginar(string $buscar, int $porPagina): LengthAwarePaginator
    {
        return Pago::query()
            ->when($buscar !== '', function ($query) use ($buscar) {
                $query->where(function ($query) use ($buscar) {
                    $query->where('numero_recibo', $buscar)
                        ->orWhereHas('vivienda', fn ($vivienda) => $vivienda->whereRaw(Busqueda::columna('codigo').' like ?', [Busqueda::patron($buscar)]));
                });
            })
            ->with(['vivienda', 'cobrador'])
            ->latest('fecha_pago')
            ->latest('id')
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * Vuelve a leer el pago reservándolo hasta terminar la transacción
     */
    public function bloquearRegistro(int $pagoId): ?Pago
    {
        return Pago::query()->lockForUpdate()->find($pagoId);
    }

    /**
     * Carga lo que muestra el detalle: vivienda, cobrador, caja, anulación y solicitudes
     */
    public function conAnulacion(Pago $pago): Pago
    {
        return $pago->load([
            'vivienda',
            'cobrador',
            'caja',
            'anuladoPor',
            'aprobadorAnulacion',
            'solicitudesAnulacion' => fn ($query) => $query->with(['solicitante', 'resolutor']),
        ]);
    }

    /**
     * Saldo de la vivienda: cargos menos pagos confirmados más ajustes
     */
    public function saldoDeVivienda(int $viviendaId): string
    {
        $saldo = DB::table('v_saldos_vivienda')->where('vivienda_id', $viviendaId)->value('saldo');

        return number_format((float) ($saldo ?? 0), 2, '.', '');
    }

    /**
     * Busca un pago sin filtrar por residencial, para el enlace firmado que se abre sin sesión
     */
    public function buscarSinResidencial(int $id): ?Pago
    {
        return Pago::query()->withoutGlobalScope('residencial')->find($id);
    }
}
