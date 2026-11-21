<?php

namespace Patronato\Negocio\Services;

use Illuminate\Support\Collection;
use Patronato\Persistencia\Models\Ajuste;
use Patronato\Persistencia\Models\Cargo;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\PagoAplicacion;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Repositorios\AjusteRepositorio;
use Patronato\Persistencia\Repositorios\CargoRepositorio;
use Patronato\Persistencia\Repositorios\PagoRepositorio;

/**
 * Estado de cuenta de una vivienda: cargos, pagos, abonos y ajustes con su saldo acumulado
 *
 * Reglas:
 * - El saldo oficial es el de la vista v_saldos_vivienda (cargos - pagos confirmados + ajustes).
 *   El acumulado de la lista se compara con él y, si no coinciden, se avisa.
 * - Solo los pagos confirmados entran a la lista. Los pendientes de verificar van aparte
 *   y los anulados o rechazados se muestran sin efecto en el saldo.
 * - Un pago es "abono" si dejó algún mes sin cubrir por completo, con lo que realmente
 *   se aplicó en pago_aplicaciones (la misma regla del cobro: monto menor que lo pendiente).
 * - Orden: fecha del movimiento (el período del cargo, la fecha del pago o del ajuste);
 *   el mismo día van primero los cargos, luego los ajustes y al final los pagos.
 * - Los movimientos migrados se marcan; un pago migrado puede no tener recibo ni meses.
 */
class EstadoCuentaService
{
    // Orden dentro del mismo día
    private const ORDEN = ['cargo' => 0, 'ajuste' => 1, 'pago' => 2];

    public function __construct(
        private CargoRepositorio $cargos,
        private PagoRepositorio $pagos,
        private AjusteRepositorio $ajustes,
        private ViviendaService $viviendas,
        private MoraService $mora,
    ) {}

    /**
     * Viviendas activas e inactivas que coinciden con la búsqueda
     *
     * @return array<int, array{id: int, codigo: string, responsable: string|null, activa: bool}>
     */
    public function buscar(string $buscar): array
    {
        return $buscar === '' ? [] : $this->viviendas->opcionesConInactivas($buscar);
    }

    /**
     * Estado de cuenta completo o de un año
     *
     * Con un año, el saldo inicial es el acumulado de todo lo anterior.
     *
     * @return array<string, mixed>
     */
    public function deVivienda(Vivienda $vivienda, ?int $anio = null): array
    {
        $cargos = $this->cargos->conAplicadoDe($vivienda->id);
        $confirmados = $this->pagos->deVivienda($vivienda->id, [Pago::CONFIRMADO]);
        $totales = $this->pagos->totalesDeVivienda($vivienda->id);

        $abonos = $this->abonos($cargos, $confirmados);

        $movimientos = collect()
            ->concat($cargos->map(fn (Cargo $cargo): array => $this->cargo($cargo)))
            ->concat($this->ajustes->deVivienda($vivienda->id)->map(fn (Ajuste $ajuste): array => $this->ajuste($ajuste)))
            ->concat($confirmados->map(fn (Pago $pago): array => $this->pago($pago, in_array($pago->id, $abonos, true))))
            ->sort(fn (array $a, array $b): int => [$a['fecha'], self::ORDEN[$a['clase']], $a['id']] <=> [$b['fecha'], self::ORDEN[$b['clase']], $b['id']])
            ->values();

        // Saldo acumulado en centavos para no perder precisión
        $acumulado = 0;
        $movimientos = $movimientos->map(function (array $movimiento) use (&$acumulado): array {
            $acumulado += $movimiento['efecto'];
            $movimiento['saldo'] = AplicacionPagoService::monto($acumulado);
            unset($movimiento['efecto']);

            return $movimiento;
        });

        $anios = $movimientos->map(fn (array $movimiento): int => (int) substr($movimiento['fecha'], 0, 4))->unique()->sortDesc()->values()->all();
        $saldoInicial = null;

        if ($anio !== null) {
            $anteriores = $movimientos->filter(fn (array $movimiento): bool => (int) substr($movimiento['fecha'], 0, 4) < $anio);
            $saldoInicial = $anteriores->isEmpty() ? '0.00' : $anteriores->last()['saldo'];
            $movimientos = $movimientos->filter(fn (array $movimiento): bool => (int) substr($movimiento['fecha'], 0, 4) === $anio)->values();
        }

        $opcion = $this->viviendas->opcionesConInactivas(viviendaId: $vivienda->id)[0] ?? null;

        return [
            'vivienda' => [
                'id' => $vivienda->id,
                'codigo' => $vivienda->codigo,
                'responsable' => $opcion['responsable'] ?? null,
                'activa' => $vivienda->activa,
            ],
            'mora' => $this->mora->estadoDe($vivienda->residencial_id, $vivienda->id),
            'totales' => $totales,
            // El acumulado de toda la historia debe dar el saldo oficial
            'cuadra' => AplicacionPagoService::monto($acumulado) === $totales['saldo'],
            'anios' => $anios,
            'anio' => $anio,
            'saldo_inicial' => $saldoInicial,
            'movimientos' => $movimientos->all(),
            'por_verificar' => $this->porVerificar($vivienda->id),
            'sin_efecto' => $this->sinEfecto($vivienda->id, $anio),
        ];
    }

    /**
     * Ids de los pagos confirmados que dejaron algún mes cubierto en parte
     *
     * Recorre las aplicaciones en el orden en que se hicieron y suma lo que cada mes
     * llevaba pagado; si tras aplicar un pago el mes sigue debiendo, ese pago fue un abono.
     *
     * @param  Collection<int, Cargo>  $cargos
     * @param  Collection<int, Pago>  $confirmados
     * @return list<int>
     */
    private function abonos(Collection $cargos, Collection $confirmados): array
    {
        $montos = $cargos->mapWithKeys(fn (Cargo $cargo): array => [$cargo->id => AplicacionPagoService::centavos($cargo->monto)])->all();
        $pagado = [];
        $abonos = [];

        $aplicaciones = $confirmados->flatMap(fn (Pago $pago) => $pago->aplicaciones)->sortBy('id');

        foreach ($aplicaciones as $aplicacion) {
            /** @var PagoAplicacion $aplicacion */
            $pagado[$aplicacion->cargo_id] = ($pagado[$aplicacion->cargo_id] ?? 0) + AplicacionPagoService::centavos($aplicacion->monto);

            if ($pagado[$aplicacion->cargo_id] < ($montos[$aplicacion->cargo_id] ?? 0)) {
                $abonos[] = $aplicacion->pago_id;
            }
        }

        return array_values(array_unique($abonos));
    }

    /**
     * Cargo del período con lo que ya se pagó de él
     *
     * @return array<string, mixed>
     */
    private function cargo(Cargo $cargo): array
    {
        $monto = AplicacionPagoService::centavos($cargo->monto);
        $aplicado = AplicacionPagoService::centavos((string) $cargo->getAttribute('aplicado'));
        $pendiente = max(0, $monto - $aplicado);

        return [
            'clase' => 'cargo',
            'id' => $cargo->id,
            'fecha' => $cargo->periodo->toDateString(),
            'tipo' => 'cargo',
            'concepto' => $cargo->concepto->nombre,
            'periodo' => $cargo->periodo->toDateString(),
            'debe' => $cargo->monto,
            'haber' => null,
            'efecto' => $monto,
            'estado_cargo' => $pendiente === 0 ? 'pagado' : ($aplicado > 0 ? 'parcial' : 'pendiente'),
            'pendiente' => AplicacionPagoService::monto($pendiente),
            'migrado' => $cargo->getAttribute('origen') === 'migracion',
        ];
    }

    /**
     * Ajuste autorizado: si es negativo reduce la deuda, si es positivo la aumenta
     *
     * @return array<string, mixed>
     */
    private function ajuste(Ajuste $ajuste): array
    {
        $centavos = AplicacionPagoService::centavos($ajuste->monto);

        return [
            'clase' => 'ajuste',
            'id' => $ajuste->id,
            'fecha' => $ajuste->fecha->toDateString(),
            'tipo' => 'ajuste',
            'concepto' => Ajuste::TIPOS[$ajuste->tipo] ?? $ajuste->tipo,
            'motivo' => $ajuste->motivo,
            'autorizado_por' => $ajuste->autorizador?->name,
            'debe' => $centavos > 0 ? AplicacionPagoService::monto($centavos) : null,
            'haber' => $centavos < 0 ? AplicacionPagoService::monto(-$centavos) : null,
            'efecto' => $centavos,
            'migrado' => $ajuste->lote_migracion_id !== null,
        ];
    }

    /**
     * Pago confirmado con los meses a los que se aplicó
     *
     * @return array<string, mixed>
     */
    private function pago(Pago $pago, bool $esAbono): array
    {
        return [
            'clase' => 'pago',
            'id' => $pago->id,
            'fecha' => $pago->fecha_pago->toDateString(),
            'tipo' => $esAbono ? 'abono' : 'pago',
            'forma_pago' => $pago->forma_pago,
            'numero_recibo' => $pago->numero_recibo,
            'referencia' => $this->referencia($pago),
            'responsable' => $pago->forma_pago === Pago::TRANSFERENCIA ? $pago->verificador?->name : $pago->cobrador?->name,
            'meses' => $pago->aplicaciones->sortBy('id')->map(fn (PagoAplicacion $aplicacion): array => [
                'periodo' => $aplicacion->cargo->periodo->toDateString(),
                'concepto' => $aplicacion->cargo->concepto->nombre,
                'monto' => $aplicacion->monto,
            ])->values()->all(),
            'debe' => null,
            'haber' => $pago->monto,
            'efecto' => -AplicacionPagoService::centavos($pago->monto),
            'migrado' => $pago->origen === 'migracion',
        ];
    }

    /**
     * Pagos que esperan verificación: no reducen el saldo hasta confirmarse
     *
     * @return array<int, array<string, mixed>>
     */
    private function porVerificar(int $viviendaId): array
    {
        return $this->pagos->deVivienda($viviendaId, [Pago::PENDIENTE_VERIFICACION, Pago::REPORTADO_POR_VECINO])
            ->map(fn (Pago $pago): array => [
                'id' => $pago->id,
                'fecha_pago' => $pago->fecha_pago->toDateString(),
                'monto' => $pago->monto,
                'forma_pago' => $pago->forma_pago,
                'referencia' => $this->referencia($pago),
                'origen' => $pago->origen,
                'dias_sin_verificar' => VerificacionTransferenciaService::diasSinVerificar($pago),
            ])
            ->values()
            ->all();
    }

    /**
     * Pagos anulados o rechazados, con su motivo; no afectan el saldo
     *
     * @return array<int, array<string, mixed>>
     */
    private function sinEfecto(int $viviendaId, ?int $anio): array
    {
        return $this->pagos->deVivienda($viviendaId, [Pago::ANULADO, Pago::RECHAZADO])
            ->filter(fn (Pago $pago): bool => $anio === null || $pago->fecha_pago->year === $anio)
            ->map(fn (Pago $pago): array => [
                'id' => $pago->id,
                'fecha_pago' => $pago->fecha_pago->toDateString(),
                'monto' => $pago->monto,
                'forma_pago' => $pago->forma_pago,
                'estado' => $pago->estado,
                'numero_recibo' => $pago->numero_recibo,
                'referencia' => $this->referencia($pago),
                'motivo' => $pago->estado === Pago::ANULADO ? $pago->motivo_anulacion : $pago->motivo_rechazo,
                'responsable' => $pago->estado === Pago::ANULADO ? $pago->anuladoPor?->name : $pago->verificador?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Banco y número de referencia de una transferencia
     */
    private function referencia(Pago $pago): ?string
    {
        if ($pago->numero_referencia === null) {
            return null;
        }

        $referencia = 'ref. '.$pago->numero_referencia;

        return $pago->banco === null ? $referencia : "{$pago->banco} · {$referencia}";
    }
}
