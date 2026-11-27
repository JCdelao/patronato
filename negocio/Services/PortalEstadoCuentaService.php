<?php

namespace Patronato\Negocio\Services;

use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Models\ViviendaResidente;
use Patronato\Persistencia\Repositorios\VinculoRepositorio;

/**
 * Estado de cuenta que el residente consulta desde el portal
 *
 * Reglas:
 * - Solo se consultan las viviendas donde el residente vive hoy (vínculo vigente),
 *   activas o inactivas; cualquier otra responde 403.
 * - Usa el mismo estado de cuenta y el mismo PDF que tesorería; no calcula saldos aparte.
 * - Los meses pendientes salen de todo el historial, aunque se consulte un solo año.
 */
class PortalEstadoCuentaService
{
    public function __construct(
        private TransferenciaService $transferencias,
        private VinculoRepositorio $vinculos,
        private EstadoCuentaService $estados,
        private EstadoCuentaPdfService $pdf,
    ) {}

    /**
     * Viviendas donde vive hoy el residente de la cuenta
     *
     * @return list<array{id: int, codigo: string, activa: bool}>
     */
    public function viviendasDe(User $usuario): array
    {
        return array_values($this->vinculos->vigentesDe($this->transferencias->residenteDe($usuario)->id)
            ->load('vivienda')
            ->unique('vivienda_id')
            ->map(fn (ViviendaResidente $vinculo): array => [
                'id' => $vinculo->vivienda_id,
                'codigo' => $vinculo->vivienda->codigo,
                'activa' => $vinculo->vivienda->activa,
            ])
            ->all());
    }

    /**
     * Estado de cuenta de una vivienda propia, con los meses que siguen pendientes
     *
     * Solo lleva lo que muestra el portal: sin nombres del personal ni datos internos de tesorería.
     *
     * @return array<string, mixed>
     */
    public function estadoDe(User $usuario, Vivienda $vivienda, ?int $anio = null): array
    {
        $this->exigirVivienda($usuario, $vivienda);

        $completo = $this->estados->deVivienda($vivienda);
        $estado = $anio === null ? $completo : $this->estados->deVivienda($vivienda, $anio);

        /** @var list<array<string, mixed>> $todos */
        $todos = $completo['movimientos'];

        /** @var list<array<string, mixed>> $movimientos */
        $movimientos = $estado['movimientos'];

        /** @var list<array<string, mixed>> $porVerificar */
        $porVerificar = $estado['por_verificar'];

        /** @var list<array<string, mixed>> $sinEfecto */
        $sinEfecto = $estado['sin_efecto'];

        /** @var array<string, mixed> $mora */
        $mora = $estado['mora'];

        /** @var array<string, mixed> $totales */
        $totales = $estado['totales'];

        return [
            'vivienda' => ['id' => $vivienda->id, 'codigo' => $vivienda->codigo, 'activa' => $vivienda->activa],
            'mora' => ['estado' => $mora['estado'], 'meses_vencidos' => $mora['meses_vencidos'], 'vencido' => $mora['vencido']],
            'totales' => ['saldo' => $totales['saldo'], 'por_verificar' => $totales['por_verificar']],
            'anios' => $estado['anios'],
            'anio' => $estado['anio'],
            'saldo_inicial' => $estado['saldo_inicial'],
            'movimientos' => array_map(fn (array $movimiento): array => $this->movimiento($movimiento), $movimientos),
            'meses_pendientes' => array_values(array_map(fn (array $cargo): array => [
                'id' => $cargo['id'],
                'periodo' => $cargo['periodo'],
                'concepto' => $cargo['concepto'],
                'monto' => $cargo['debe'],
                'pendiente' => $cargo['pendiente'],
                'estado' => $cargo['estado_cargo'],
            ], array_filter($todos, fn (array $movimiento): bool => $movimiento['clase'] === 'cargo' && $movimiento['estado_cargo'] !== 'pagado'))),
            'por_verificar' => array_map(fn (array $pago): array => [
                'id' => $pago['id'],
                'fecha_pago' => $pago['fecha_pago'],
                'monto' => $pago['monto'],
                'forma_pago' => $pago['forma_pago'],
                'estado' => $pago['estado'],
                'referencia' => $pago['referencia'],
            ], $porVerificar),
            'sin_efecto' => array_map(fn (array $pago): array => [
                'id' => $pago['id'],
                'fecha_pago' => $pago['fecha_pago'],
                'monto' => $pago['monto'],
                'estado' => $pago['estado'],
                'motivo' => $pago['motivo'],
            ], $sinEfecto),
        ];
    }

    /**
     * Rango que propone el formulario del PDF
     *
     * @return array{desde: string, hasta: string}
     */
    public function rangoPredeterminado(): array
    {
        return $this->pdf->rangoPredeterminado();
    }

    /**
     * Genera el PDF del estado de cuenta de una vivienda propia
     *
     * @return array{contenido: string, tipo: string, nombre: string}
     */
    public function pdf(User $usuario, Vivienda $vivienda, string $desde, string $hasta): array
    {
        $this->exigirVivienda($usuario, $vivienda);

        return $this->pdf->generar($usuario, $vivienda, $desde, $hasta);
    }

    /**
     * Movimiento del historial con los datos que ve el residente
     *
     * @param  array<string, mixed>  $movimiento
     * @return array<string, mixed>
     */
    private function movimiento(array $movimiento): array
    {
        $datos = [
            'clase' => $movimiento['clase'],
            'id' => $movimiento['id'],
            'fecha' => $movimiento['fecha'],
            'tipo' => $movimiento['tipo'],
            'debe' => $movimiento['debe'],
            'haber' => $movimiento['haber'],
            'saldo' => $movimiento['saldo'],
        ];

        /** @var list<array{periodo: string}> $meses */
        $meses = $movimiento['meses'] ?? [];

        return match ($movimiento['clase']) {
            'cargo' => [...$datos, 'concepto' => $movimiento['concepto'], 'periodo' => $movimiento['periodo']],
            'ajuste' => [...$datos, 'concepto' => $movimiento['concepto'], 'motivo' => $movimiento['motivo']],
            default => [
                ...$datos,
                'forma_pago' => $movimiento['forma_pago'],
                'numero_recibo' => $movimiento['numero_recibo'],
                'referencia' => $movimiento['referencia'],
                'meses' => array_map(fn (array $mes): array => ['periodo' => $mes['periodo']], $meses),
            ],
        };
    }

    /**
     * Rechaza las viviendas donde el residente no vive hoy
     */
    private function exigirVivienda(User $usuario, Vivienda $vivienda): void
    {
        $residente = $this->transferencias->residenteDe($usuario);

        abort_unless($this->vinculos->viveEn($residente->id, $vivienda->id), 403, 'Solo puedes consultar las viviendas donde vives.');
    }
}
