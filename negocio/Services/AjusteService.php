<?php

namespace Patronato\Negocio\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\Ajuste;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\AjusteRepositorio;
use Patronato\Persistencia\Repositorios\PagoRepositorio;
use Patronato\Persistencia\Repositorios\ViviendaRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Correcciones autorizadas al saldo de una vivienda
 *
 * Reglas:
 * - Solo se registran correcciones, que aumentan o disminuyen el saldo general;
 *   el saldo puede quedar por debajo de cero.
 * - No tocan cargos, pagos, sus aplicaciones, la mora ni el saldo a favor:
 *   solo entran a la vista de saldos y al estado de cuenta.
 * - La fecha es la del día en Honduras y el motivo tiene al menos 10 caracteres.
 * - Solo las registran el presidente y el tesorero con el permiso ajustes.aprobar;
 *   quien la registra queda como autorizador.
 * - Vale para viviendas activas e inactivas del residencial.
 * - Cada corrección queda en la auditoría con el saldo antes y después.
 */
class AjusteService
{
    public const MOTIVO_MINIMO = 10;

    public const AUMENTA = 'aumenta';

    public const DISMINUYE = 'disminuye';

    public function __construct(
        private AjusteRepositorio $ajustes,
        private ViviendaRepositorio $viviendas,
        private PagoRepositorio $pagos,
        private ViviendaService $opciones,
        private PeriodoService $periodos,
        private PermisoService $permisos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Indica si el usuario puede registrar correcciones: necesita el permiso y un rol autorizado
     */
    public function puedeRegistrar(User $usuario): bool
    {
        return $this->permisos->puede($usuario, 'ajustes', 'aprobar')
            && $this->permisos->rolPuedeTener($usuario->rol?->codigo, 'ajustes.aprobar');
    }

    /**
     * Viviendas activas e inactivas que coinciden con la búsqueda
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscar(string $buscar): array
    {
        return $buscar === '' ? [] : $this->opciones->opcionesConInactivas($buscar);
    }

    /**
     * Vivienda elegida con su saldo actual y sus ajustes; null si no es del residencial
     *
     * @return array{vivienda: array<string, mixed>, saldo: string, ajustes: list<array<string, mixed>>}|null
     */
    public function deVivienda(int $viviendaId): ?array
    {
        $opcion = $this->opciones->opcionesConInactivas(viviendaId: $viviendaId)[0] ?? null;

        if ($opcion === null) {
            return null;
        }

        return [
            'vivienda' => $opcion,
            'saldo' => $this->pagos->saldoDeVivienda($viviendaId),
            'ajustes' => array_values($this->ajustes->deVivienda($viviendaId)->sortByDesc('id')->map(fn (Ajuste $ajuste): array => [
                'id' => $ajuste->id,
                'fecha' => $ajuste->fecha->toDateString(),
                'monto' => $ajuste->monto,
                'tipo' => Ajuste::TIPOS[$ajuste->tipo] ?? $ajuste->tipo,
                'motivo' => $ajuste->motivo,
                'autorizado_por' => $ajuste->autorizador?->name,
            ])->all()),
        ];
    }

    /**
     * Registra una corrección al saldo de la vivienda
     *
     * El monto llega positivo; se guarda negativo si disminuye el saldo.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function registrar(User $usuario, int $viviendaId, string $sentido, string $monto, string $motivo): Ajuste
    {
        if (! $this->puedeRegistrar($usuario)) {
            throw new AuthorizationException('Solo el presidente y el tesorero pueden registrar ajustes.');
        }

        $motivo = trim($motivo);
        $centavos = AplicacionPagoService::centavos($monto);

        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw ValidationException::withMessages(['motivo' => 'Escribe un motivo de al menos '.self::MOTIVO_MINIMO.' caracteres.']);
        }

        if (! in_array($sentido, [self::AUMENTA, self::DISMINUYE], true)) {
            throw ValidationException::withMessages(['sentido' => 'Indica si la corrección aumenta o disminuye el saldo.']);
        }

        if ($centavos <= 0) {
            throw ValidationException::withMessages(['monto' => 'El monto debe ser mayor que cero.']);
        }

        $vivienda = $this->viviendas->buscar($viviendaId);

        // Una vivienda de otro residencial no se puede corregir aunque llegue su id
        if ($vivienda === null || (int) $vivienda->residencial_id !== (int) $usuario->residencial_id) {
            throw ValidationException::withMessages(['vivienda_id' => 'Selecciona una vivienda válida.']);
        }

        $conSigno = $sentido === self::DISMINUYE ? -$centavos : $centavos;

        return $this->transaccion->ejecutar(function () use ($usuario, $vivienda, $conSigno, $motivo) {
            $saldoAnterior = $this->pagos->saldoDeVivienda($vivienda->id);

            $ajuste = $this->ajustes->crear([
                'residencial_id' => $vivienda->residencial_id,
                'vivienda_id' => $vivienda->id,
                'tipo' => Ajuste::CORRECCION,
                'monto' => AplicacionPagoService::monto($conSigno),
                // La fecha es la del día en Honduras, no la de UTC
                'fecha' => $this->periodos->hoy()->toDateString(),
                'motivo' => $motivo,
                'autorizado_por' => $usuario->id,
            ]);

            $this->auditoria->registrar($usuario, 'ajustes', 'ajuste_registrado', $ajuste, [
                'valor_anterior' => ['saldo' => $saldoAnterior],
                'valor_nuevo' => [
                    'vivienda_id' => $vivienda->id,
                    'tipo' => Ajuste::CORRECCION,
                    'monto' => $ajuste->monto,
                    'fecha' => $ajuste->fecha->toDateString(),
                    'saldo' => $this->pagos->saldoDeVivienda($vivienda->id),
                ],
                'motivo' => $motivo,
            ]);

            return $ajuste;
        });
    }
}
