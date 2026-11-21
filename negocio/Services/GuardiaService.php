<?php

namespace Patronato\Negocio\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\GuardiaRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Guardias de vigilancia con sus datos y su cuenta
 */
class GuardiaService
{
    private const POR_PAGINA = 25;

    public function __construct(
        private GuardiaRepositorio $guardias,
        private CuentaService $cuentas,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Guardias activos o inactivos para el listado
     *
     * @return LengthAwarePaginator<int, array{id: int, nombre: string, identidad: string, telefono: string|null, turno: string, activo: bool, cuenta: array{email: string, activa: bool}|null}>
     */
    public function listado(bool $activos, string $buscar): LengthAwarePaginator
    {
        return $this->guardias->paginar($activos, $buscar, self::POR_PAGINA)->through(fn (Guardia $guardia): array => [
            'id' => $guardia->id,
            'nombre' => $guardia->nombre,
            'identidad' => $guardia->identidad,
            'telefono' => $guardia->telefono,
            'turno' => $guardia->turno,
            'activo' => $guardia->activo,
            'cuenta' => $guardia->cuenta === null ? null : [
                'email' => $guardia->cuenta->email,
                'activa' => $guardia->cuenta->activo,
            ],
        ]);
    }

    /**
     * Registra un guardia activo
     *
     * @param  array{nombre: string, identidad: string, telefono: string, turno: string}  $datos
     *
     * @throws ValidationException
     */
    public function registrar(User $usuario, array $datos): Guardia
    {
        return $this->guardandoIdentidad(fn () => $this->transaccion->ejecutar(function () use ($usuario, $datos) {
            $guardia = $this->guardias->crear([
                'residencial_id' => $usuario->residencial_id,
                ...$this->campos($datos),
                'activo' => true,
            ]);

            $this->auditoria->registrar($usuario, 'vigilancia', 'guardia_registrado', $guardia, [
                'valor_nuevo' => $this->resumen($guardia),
            ]);

            return $guardia;
        }));
    }

    /**
     * Corrige los datos de un guardia activo
     *
     * @param  array{nombre: string, identidad: string, telefono: string, turno: string}  $datos
     *
     * @throws ValidationException
     */
    public function actualizar(User $usuario, Guardia $guardia, array $datos): void
    {
        $this->guardandoIdentidad(fn () => $this->transaccion->ejecutar(function () use ($usuario, $guardia, $datos) {
            $anterior = $this->resumen($guardia);

            $this->guardias->actualizar($guardia, $this->campos($datos));

            $nuevo = $this->resumen($guardia);
            $cambiados = array_keys(array_diff_assoc($nuevo, $anterior));

            if ($cambiados !== []) {
                $this->auditoria->registrar($usuario, 'vigilancia', 'guardia_editado', $guardia, [
                    'valor_anterior' => array_intersect_key($anterior, array_flip($cambiados)),
                    'valor_nuevo' => array_intersect_key($nuevo, array_flip($cambiados)),
                ]);
            }
        }));
    }

    /**
     * Activa o desactiva un guardia; su cuenta cambia con él
     *
     * @throws ValidationException si ya estaba en ese estado
     */
    public function cambiarActivo(User $usuario, Guardia $guardia, bool $activo): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $guardia, $activo) {
            // Se relee bloqueado para que dos cambios a la vez no se registren ambos
            $actual = $this->guardias->bloquearRegistro($guardia->id);

            if ($actual === null || $actual->activo === $activo) {
                throw ValidationException::withMessages(['guardia' => $activo ? 'El guardia ya está activo.' : 'El guardia ya está inactivo.']);
            }

            $this->guardias->actualizar($actual, ['activo' => $activo]);

            $this->auditoria->registrar($usuario, 'vigilancia', $activo ? 'guardia_activado' : 'guardia_desactivado', $actual, [
                'valor_anterior' => ['activo' => ! $activo],
                'valor_nuevo' => ['activo' => $activo],
            ]);

            // Un guardia inactivo no entra al sistema
            $cuenta = $actual->cuenta;

            if ($cuenta !== null && $cuenta->activo !== $activo) {
                $this->cuentas->cambiarActivo($usuario, $cuenta, $activo);
            }
        });
    }

    /**
     * Crea la cuenta con el rol guardia a un guardia activo que todavía no tiene una
     *
     * @return array{usuario: User, password_temporal: string, correo_enviado: bool}
     *
     * @throws ValidationException
     */
    public function crearCuenta(User $usuario, Guardia $guardia, string $correo): array
    {
        $this->exigirSinCuenta($guardia);

        return $this->cuentas->crearDeGuardia($usuario, $guardia->nombre, $correo, function (User $cuenta) use ($guardia) {
            // Se relee bloqueado para que dos solicitudes a la vez no creen dos cuentas
            $actual = $this->guardias->bloquearRegistro($guardia->id);

            $this->exigirSinCuenta($actual);

            $this->guardias->actualizar($actual, ['user_id' => $cuenta->id]);
        });
    }

    /**
     * @throws ValidationException
     */
    private function exigirSinCuenta(?Guardia $guardia): void
    {
        if ($guardia === null || ! $guardia->activo) {
            throw ValidationException::withMessages(['email' => 'Solo se crea una cuenta a un guardia activo.']);
        }

        if ($guardia->user_id !== null) {
            throw ValidationException::withMessages(['email' => 'El guardia ya tiene una cuenta.']);
        }
    }

    /**
     * La base rechaza dos guardias con la misma identidad aunque se registren al mismo tiempo
     *
     * @template T
     *
     * @param  callable(): T  $operacion
     * @return T
     *
     * @throws ValidationException
     */
    private function guardandoIdentidad(callable $operacion): mixed
    {
        try {
            return $operacion();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['identidad' => 'Ya hay un guardia con esa identidad en el residencial.']);
        }
    }

    /**
     * @param  array{nombre: string, identidad: string, telefono: string, turno: string}  $datos
     * @return array{nombre: string, identidad: string, telefono: string, turno: string}
     */
    private function campos(array $datos): array
    {
        return [
            'nombre' => trim($datos['nombre']),
            'identidad' => trim($datos['identidad']),
            'telefono' => trim($datos['telefono']),
            'turno' => $datos['turno'],
        ];
    }

    /**
     * @return array{nombre: string, identidad: string, telefono: string|null, turno: string}
     */
    private function resumen(Guardia $guardia): array
    {
        return [
            'nombre' => $guardia->nombre,
            'identidad' => $guardia->identidad,
            'telefono' => $guardia->telefono,
            'turno' => $guardia->turno,
        ];
    }
}
