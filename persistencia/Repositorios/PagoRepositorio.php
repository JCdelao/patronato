<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
     * Pagos confirmados del residencial que todavía tienen monto sin aplicar
     *
     * Lo aplicado suma todas las aplicaciones del pago, de cualquier origen. Los pagos
     * migrados (pagos.origen = 'migracion') no cuentan. Ordenados por vivienda y, dentro
     * de cada una, del pago más antiguo al más reciente. Cada pago trae 'aplicado'.
     *
     * Bloquea los pagos que devuelve hasta terminar la transacción: si una anulación de
     * uno de ellos está en curso, espera a que termine y ese pago ya no se devuelve.
     *
     * @return Collection<int, Pago>
     */
    public function conSaldoDisponible(int $residencialId): Collection
    {
        $aplicado = 'select coalesce(sum(pago_aplicaciones.monto), 0) from pago_aplicaciones where pago_aplicaciones.pago_id = pagos.id';

        return Pago::query()
            ->withoutGlobalScope('residencial')
            ->where('pagos.residencial_id', $residencialId)
            ->where('pagos.estado', Pago::CONFIRMADO)
            ->where('pagos.origen', '<>', 'migracion')
            ->whereRaw("pagos.monto > ({$aplicado})")
            ->select('pagos.*')
            ->selectRaw("({$aplicado}) as aplicado")
            ->orderBy('pagos.vivienda_id')
            ->orderBy('pagos.fecha_pago')
            ->orderBy('pagos.id')
            ->lockForUpdate()
            ->get();
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
            'verificador',
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
     * Indica si el residencial ya tiene un pago vigente con esa referencia en ese banco
     *
     * Sigue la regla del índice pagos_referencia_banco_unique: el banco no distingue
     * mayúsculas y los pagos rechazados o anulados liberan la referencia.
     */
    public function existeReferencia(string $banco, string $numeroReferencia): bool
    {
        return Pago::query()
            ->whereRaw('lower(banco) = ?', [mb_strtolower($banco)])
            ->where('numero_referencia', $numeroReferencia)
            ->whereNotIn('estado', [Pago::RECHAZADO, Pago::ANULADO])
            ->exists();
    }

    /**
     * Transferencias que la cuenta reportó desde el portal, de la más reciente a la más antigua
     *
     * @return Collection<int, Pago>
     */
    public function reportesDe(int $usuarioId): Collection
    {
        return Pago::query()
            ->where('origen', Pago::PORTAL)
            ->where('registrado_por', $usuarioId)
            ->with('vivienda')
            ->latest('fecha_pago')
            ->latest('id')
            ->get();
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
            'verificador',
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
     * Totales de la vivienda según la vista oficial de saldos
     *
     * saldo = cargos - pagos confirmados + ajustes; por_verificar no entra al saldo.
     *
     * @return array{cargos: string, pagos_confirmados: string, ajustes: string, saldo: string, por_verificar: string}
     */
    public function totalesDeVivienda(int $viviendaId): array
    {
        $fila = DB::table('v_saldos_vivienda')->where('vivienda_id', $viviendaId)->first();
        $monto = fn (string $columna): string => number_format((float) ($fila->{$columna} ?? 0), 2, '.', '');

        return [
            'cargos' => $monto('total_cargos'),
            'pagos_confirmados' => $monto('total_pagos_confirmados'),
            'ajustes' => $monto('total_ajustes'),
            'saldo' => $monto('saldo'),
            'por_verificar' => $monto('total_por_verificar'),
        ];
    }

    /**
     * Pagos de una vivienda en los estados indicados, del más antiguo al más reciente
     *
     * Trae los meses a los que se aplicó cada pago y quién lo cobró, verificó o anuló.
     *
     * @param  list<string>  $estados
     * @return Collection<int, Pago>
     */
    public function deVivienda(int $viviendaId, array $estados): Collection
    {
        return Pago::query()
            ->with(['aplicaciones.cargo.concepto', 'cobrador:id,name', 'verificador:id,name', 'anuladoPor:id,name'])
            ->where('vivienda_id', $viviendaId)
            ->whereIn('estado', $estados)
            ->orderBy('fecha_pago')
            ->orderBy('id')
            ->get();
    }

    /**
     * Busca un pago sin filtrar por residencial, para el enlace firmado que se abre sin sesión
     */
    public function buscarSinResidencial(int $id): ?Pago
    {
        return Pago::query()->withoutGlobalScope('residencial')->find($id);
    }

    /**
     * Transferencias del residencial que esperan verificación, de la más antigua a la más reciente
     *
     * @return Collection<int, Pago>
     */
    public function porVerificar(): Collection
    {
        return $this->consultaPorVerificar()
            ->with(['vivienda', 'cobrador'])
            ->oldest()
            ->oldest('id')
            ->get();
    }

    /**
     * Cantidad de transferencias del residencial que esperan verificación
     */
    public function cantidadPorVerificar(): int
    {
        return $this->consultaPorVerificar()->count();
    }

    /**
     * Carga lo que muestra la verificación: vivienda, quien la registró y quien la verificó
     */
    public function conVerificacion(Pago $pago): Pago
    {
        return $pago->loadMissing(['vivienda', 'cobrador', 'verificador']);
    }

    /**
     * @return Builder<Pago>
     */
    private function consultaPorVerificar(): Builder
    {
        return Pago::query()
            ->where('forma_pago', Pago::TRANSFERENCIA)
            ->whereIn('estado', [Pago::PENDIENTE_VERIFICACION, Pago::REPORTADO_POR_VECINO]);
    }
}
