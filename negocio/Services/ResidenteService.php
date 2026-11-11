<?php

namespace Patronato\Negocio\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\ViviendaResidente;
use Patronato\Persistencia\Repositorios\ResidenteRepositorio;
use Patronato\Persistencia\Repositorios\VinculoRepositorio;
use Patronato\Persistencia\Transaccion;

class ResidenteService
{
    public function __construct(
        private ResidenteRepositorio $residentes,
        private VinculoRepositorio $vinculos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Residentes inactivos con las viviendas donde vivieron
     *
     * @return LengthAwarePaginator<int, Residente>
     */
    public function inactivos(string $buscar): LengthAwarePaginator
    {
        return $this->residentes->paginarInactivos($buscar, 24);
    }

    /**
     * Registra un residente en una vivienda
     *
     * @param  array{vivienda_id: int|string, tipo: string, nombres: string, apellidos: string, identidad?: string|null, telefono?: string|null, correo?: string|null}  $datos
     */
    public function registrar(User $usuario, array $datos, bool $esResponsable): Residente
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $datos, $esResponsable) {
            $residente = $this->residentes->crear([
                'residencial_id' => $usuario->residencial_id,
                ...$this->datosPersonales($datos),
            ]);

            $this->vincular($residente, (int) $datos['vivienda_id'], $datos['tipo'], $esResponsable);

            $this->auditoria->registrar($usuario, 'padron', 'residente_registrado', $residente, [
                'valor_nuevo' => [...$this->datosPersonales($datos), 'vivienda_id' => (int) $datos['vivienda_id'], 'tipo' => $datos['tipo']],
            ]);

            return $residente;
        });
    }

    /**
     * Actualiza un residente y su vínculo con una de sus viviendas
     *
     * @param  array{vivienda_id: int|string, tipo: string, nombres: string, apellidos: string, identidad?: string|null, telefono?: string|null, correo?: string|null}  $datos
     *
     * @throws ValidationException
     */
    public function actualizar(User $usuario, Residente $residente, ?int $viviendaOrigenId, array $datos, bool $esResponsable): void
    {
        $vinculo = $this->vinculos->vigentesDe($residente->id, $viviendaOrigenId)->first();

        if ($vinculo === null) {
            throw ValidationException::withMessages(['vivienda_id' => 'El residente ya no vive en esa vivienda.']);
        }

        $viviendaId = (int) $datos['vivienda_id'];

        if ($vinculo->vivienda_id !== $viviendaId && $this->vinculos->viveEn($residente->id, $viviendaId)) {
            throw ValidationException::withMessages(['vivienda_id' => 'El residente ya vive en esa vivienda.']);
        }

        $this->transaccion->ejecutar(function () use ($usuario, $residente, $vinculo, $viviendaId, $datos, $esResponsable) {
            $anterior = [...$this->datosPersonales($residente->toArray()), 'vivienda_id' => $vinculo->vivienda_id, 'tipo' => $vinculo->tipo];

            $this->residentes->actualizar($residente, $this->datosPersonales($datos));

            if ($vinculo->vivienda_id !== $viviendaId) {
                $this->cerrar($vinculo);
                $this->vincular($residente, $viviendaId, $datos['tipo'], $esResponsable);
            } else {
                if ($esResponsable) {
                    $this->vinculos->quitarResponsables($viviendaId);
                }

                $this->vinculos->actualizar($vinculo, ['tipo' => $datos['tipo'], 'es_responsable_pago' => $esResponsable]);

                $this->asegurarResponsable($viviendaId);
            }

            $nuevo = [...$this->datosPersonales($datos), 'vivienda_id' => $viviendaId, 'tipo' => $datos['tipo']];

            if ($anterior !== $nuevo) {
                $this->auditoria->registrar($usuario, 'padron', 'residente_editado', $residente, [
                    'valor_anterior' => array_diff_assoc($anterior, $nuevo),
                    'valor_nuevo' => array_diff_assoc($nuevo, $anterior),
                ]);
            }
        });
    }

    /**
     * Agrega una vivienda a un residente y lo reactiva si estaba inactivo
     *
     * @param  array{vivienda_id: int|string, tipo: string}  $datos
     *
     * @throws ValidationException
     */
    public function agregarVivienda(User $usuario, Residente $residente, array $datos, bool $esResponsable): void
    {
        $viviendaId = (int) $datos['vivienda_id'];

        if ($this->vinculos->viveEn($residente->id, $viviendaId)) {
            throw ValidationException::withMessages(['vivienda_id' => 'El residente ya vive en esa vivienda.']);
        }

        $this->transaccion->ejecutar(function () use ($usuario, $residente, $viviendaId, $datos, $esResponsable) {
            $reactivado = ! $residente->activo;

            if ($reactivado) {
                $this->residentes->actualizar($residente, ['activo' => true]);
            }

            $this->vincular($residente, $viviendaId, $datos['tipo'], $esResponsable);

            $this->auditoria->registrar($usuario, 'padron', $reactivado ? 'residente_reactivado' : 'residente_vinculado', $residente, [
                'valor_nuevo' => ['vivienda_id' => $viviendaId, 'tipo' => $datos['tipo']],
            ]);
        });
    }

    /**
     * Saca a un residente de una vivienda, o de todas, y lo deja inactivo si ya no vive en ninguna
     */
    public function darDeBaja(User $usuario, Residente $residente, ?int $viviendaId = null): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $residente, $viviendaId) {
            $vinculos = $this->vinculos->vigentesDe($residente->id, $viviendaId);

            $vinculos->each(fn (ViviendaResidente $vinculo) => $this->cerrar($vinculo));

            $sigue = $this->vinculos->tieneViviendas($residente->id);

            if (! $sigue) {
                $this->residentes->actualizar($residente, ['activo' => false]);
            }

            $this->auditoria->registrar($usuario, 'padron', $sigue ? 'residente_retirado_de_vivienda' : 'residente_dado_de_baja', $residente, [
                'valor_anterior' => ['viviendas' => $vinculos->pluck('vivienda_id')->all()],
            ]);
        });
    }

    /**
     * Asocia o libera la cuenta de un residente
     */
    public function vincularCuenta(int $usuarioId, ?int $residenteId): void
    {
        $this->residentes->asignarCuenta($usuarioId, $residenteId);
    }

    /**
     * Ids de todos los residentes que vivieron en la vivienda
     *
     * @return list<int>
     */
    public function historicosDe(int $viviendaId): array
    {
        return $this->vinculos->residentesDe($viviendaId);
    }

    /**
     * Residentes activos que pueden recibir una cuenta
     *
     * @return array<int, array{id: int, nombre: string, vivienda: string|null, user_id: int|null}>
     */
    public function paraCuentas(): array
    {
        return $this->residentes->activosConViviendas()
            ->map(fn (Residente $residente) => [
                'id' => $residente->id,
                'nombre' => "{$residente->nombres} {$residente->apellidos}",
                'vivienda' => $residente->vinculosVigentes
                    ->map(fn (ViviendaResidente $vinculo) => $vinculo->vivienda->codigo)
                    ->sort()
                    ->implode(', ') ?: null,
                'user_id' => $residente->user_id,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, string|null>
     */
    private function datosPersonales(array $datos): array
    {
        return [
            'nombres' => $datos['nombres'],
            'apellidos' => $datos['apellidos'],
            'identidad' => $datos['identidad'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'correo' => $datos['correo'] ?? null,
        ];
    }

    private function vincular(Residente $residente, int $viviendaId, string $tipo, bool $esResponsable): void
    {
        if ($esResponsable) {
            $this->vinculos->quitarResponsables($viviendaId);
        }

        $this->vinculos->crear([
            'residencial_id' => $residente->residencial_id,
            'vivienda_id' => $viviendaId,
            'residente_id' => $residente->id,
            'tipo' => $tipo,
            'es_responsable_pago' => $esResponsable,
            'fecha_inicio' => now()->toDateString(),
        ]);

        $this->asegurarResponsable($viviendaId);
    }

    private function cerrar(ViviendaResidente $vinculo): void
    {
        $this->vinculos->actualizar($vinculo, ['fecha_fin' => now()->toDateString(), 'es_responsable_pago' => false]);

        $this->asegurarResponsable($vinculo->vivienda_id);
    }

    /**
     * Asegura un responsable de pago en cada vivienda habitada
     */
    private function asegurarResponsable(int $viviendaId): void
    {
        if ($this->vinculos->tieneResponsable($viviendaId)) {
            return;
        }

        $primero = $this->vinculos->primeroDe($viviendaId);

        if ($primero !== null) {
            $this->vinculos->actualizar($primero, ['es_responsable_pago' => true]);
        }
    }
}
