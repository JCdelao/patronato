<?php

namespace Patronato\Negocio\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Patronato\Negocio\Credenciales\EstadoCredencial;
use Patronato\Persistencia\Models\Sticker;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vehiculo;
use Patronato\Persistencia\Repositorios\StickerRepositorio;
use Patronato\Persistencia\Repositorios\VehiculoRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Stickers numerados de los vehículos y sus cambios de estado
 *
 * Cada estado decide qué acciones admite (patrón State, en negocio/Credenciales).
 */
class StickerService
{
    public const MOTIVO_MINIMO = 10;

    private const NUMERO_OCUPADO = 'Ya hay un sticker activo con ese número en el residencial.';

    /**
     * Acción de la bitácora para cada cambio de estado
     */
    private const AUDITORIA = [
        EstadoCredencial::SUSPENDER => 'sticker_suspendido',
        EstadoCredencial::REACTIVAR => 'sticker_reactivado',
        EstadoCredencial::VENCER => 'sticker_vencido',
        EstadoCredencial::DAR_DE_BAJA => 'sticker_dado_de_baja',
    ];

    public function __construct(
        private StickerRepositorio $stickers,
        private VehiculoRepositorio $vehiculos,
        private PeriodoService $periodos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Número sin espacios en los extremos y en mayúsculas; los ceros a la izquierda se conservan
     */
    public function normalizarNumero(string $numero): string
    {
        return mb_strtoupper(trim($numero));
    }

    /**
     * Motivo por el que no se puede usar el número, o null si está libre
     */
    public function motivoNumeroNoDisponible(string $numero): ?string
    {
        return $this->stickers->existeNumeroActivo($this->normalizarNumero($numero)) ? self::NUMERO_OCUPADO : null;
    }

    /**
     * Acciones disponibles para un sticker según su estado
     *
     * @return list<string>
     */
    public function acciones(Sticker $sticker): array
    {
        return EstadoCredencial::de($sticker->estado)->acciones();
    }

    /**
     * Asigna el primer sticker, o uno nuevo después de que el anterior terminó
     *
     * @throws ValidationException si el vehículo está de baja o ya tiene un sticker vigente
     */
    public function asignar(User $usuario, Vehiculo $vehiculo, string $numero): Sticker
    {
        $numero = $this->normalizarNumero($numero);

        return $this->guardandoNumero(fn () => $this->transaccion->ejecutar(function () use ($usuario, $vehiculo, $numero) {
            $this->exigirVehiculoActivo($vehiculo);

            if ($this->stickers->bloquearVigenteDe($vehiculo->id) !== null) {
                throw ValidationException::withMessages(['numero' => 'El vehículo ya tiene un sticker; para cambiarlo, reemplázalo.']);
            }

            $sticker = $this->nuevo($vehiculo, $numero);

            $this->auditoria->registrar($usuario, 'vehiculos', 'sticker_asignado', $sticker, [
                'valor_nuevo' => ['vehiculo_id' => $vehiculo->id, 'placa' => $vehiculo->placa, 'numero' => $numero],
            ]);

            return $sticker;
        }));
    }

    /**
     * Sustituye un sticker por otro con su motivo; también renueva uno vencido
     *
     * @throws ValidationException
     */
    public function reemplazar(User $usuario, Sticker $sticker, string $numero, string $motivo): Sticker
    {
        $numero = $this->normalizarNumero($numero);

        return $this->guardandoNumero(fn () => $this->transaccion->ejecutar(function () use ($usuario, $sticker, $numero, $motivo) {
            $actual = $this->exigirAccion($sticker, EstadoCredencial::REEMPLAZAR);
            $estadoAnterior = $actual->estado;
            $this->exigirVehiculoActivo($actual->vehiculo);

            // El anterior deja de estar activo antes de crear el nuevo, por si lleva el mismo número
            $this->stickers->actualizar($actual, ['estado' => Sticker::REEMPLAZADO, 'motivo' => trim($motivo)]);

            if ($this->stickers->existeNumeroActivo($numero)) {
                throw ValidationException::withMessages(['numero' => self::NUMERO_OCUPADO]);
            }

            $nuevo = $this->nuevo($actual->vehiculo, $numero);

            $this->auditoria->registrar($usuario, 'vehiculos', 'sticker_reemplazado', $actual, [
                'valor_anterior' => ['numero' => $actual->numero, 'estado' => $estadoAnterior],
                'valor_nuevo' => ['numero' => $nuevo->numero, 'sticker_id' => $nuevo->id],
                'motivo' => trim($motivo),
            ]);

            return $nuevo;
        }));
    }

    /**
     * Suspende, reactiva o marca como vencido un sticker
     *
     * @throws ValidationException si el estado actual no admite la acción
     */
    public function cambiarEstado(User $usuario, Sticker $sticker, string $accion, string $motivo): void
    {
        $this->guardandoNumero(fn () => $this->transaccion->ejecutar(function () use ($usuario, $sticker, $accion, $motivo) {
            $actual = $this->exigirAccion($sticker, $accion);

            if ($accion === EstadoCredencial::REACTIVAR && $this->stickers->existeNumeroActivo($actual->numero, $actual->id)) {
                throw ValidationException::withMessages(['accion' => 'Otro sticker activo ya usa ese número; reemplázalo por uno nuevo.']);
            }

            $this->transicion($usuario, $actual, $accion, $motivo);
        }));
    }

    /**
     * Retira definitivamente un sticker
     *
     * @throws ValidationException si ya terminó
     */
    public function darDeBaja(User $usuario, Sticker $sticker, string $motivo): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $sticker, $motivo) {
            $this->transicion($usuario, $this->exigirAccion($sticker, EstadoCredencial::DAR_DE_BAJA), EstadoCredencial::DAR_DE_BAJA, $motivo);
        });
    }

    /**
     * Al dar de baja un vehículo, su sticker vigente se da de baja con el mismo motivo
     *
     * Corre dentro de la transacción de la baja del vehículo.
     */
    public function darDeBajaDeVehiculo(User $usuario, Vehiculo $vehiculo, string $motivo): void
    {
        $sticker = $this->stickers->bloquearVigenteDe($vehiculo->id);

        if ($sticker !== null) {
            $this->transicion($usuario, $sticker, EstadoCredencial::DAR_DE_BAJA, $motivo);
        }
    }

    /**
     * Relee el sticker bloqueado y comprueba que su estado admita la acción
     *
     * @throws ValidationException
     */
    private function exigirAccion(Sticker $sticker, string $accion): Sticker
    {
        $actual = $this->stickers->bloquearRegistro($sticker->id);

        if ($actual === null || ! EstadoCredencial::de($actual->estado)->permite($accion)) {
            throw ValidationException::withMessages(['accion' => 'El sticker ya no admite ese cambio; actualiza la página.']);
        }

        return $actual;
    }

    /**
     * @throws ValidationException
     */
    private function exigirVehiculoActivo(Vehiculo $vehiculo): void
    {
        $actual = $this->vehiculos->bloquearRegistro($vehiculo->id);

        if ($actual === null || ! $actual->activo) {
            throw ValidationException::withMessages(['numero' => 'El vehículo está dado de baja.']);
        }
    }

    /**
     * Lleva el sticker al estado que indica su estado actual para la acción y lo audita
     */
    private function transicion(User $usuario, Sticker $sticker, string $accion, string $motivo): void
    {
        $anterior = $sticker->estado;
        $siguiente = (string) EstadoCredencial::de($anterior)->siguiente($accion);

        $this->stickers->actualizar($sticker, ['estado' => $siguiente, 'motivo' => trim($motivo)]);

        $this->auditoria->registrar($usuario, 'vehiculos', self::AUDITORIA[$accion], $sticker, [
            'valor_anterior' => ['estado' => $anterior],
            'valor_nuevo' => ['estado' => $siguiente],
            'motivo' => trim($motivo),
        ]);
    }

    private function nuevo(Vehiculo $vehiculo, string $numero): Sticker
    {
        return $this->stickers->crear([
            'residencial_id' => $vehiculo->residencial_id,
            'vehiculo_id' => $vehiculo->id,
            'numero' => $numero,
            'estado' => Sticker::ACTIVO,
            'fecha_emision' => $this->periodos->hoy()->toDateString(),
        ]);
    }

    /**
     * La base rechaza dos stickers activos con el mismo número aunque se guarden al mismo tiempo
     *
     * @template T
     *
     * @param  callable(): T  $operacion
     * @return T
     *
     * @throws ValidationException
     */
    private function guardandoNumero(callable $operacion): mixed
    {
        try {
            return $operacion();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['numero' => self::NUMERO_OCUPADO]);
        }
    }
}
