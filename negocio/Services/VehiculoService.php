<?php

namespace Patronato\Negocio\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vehiculo;
use Patronato\Persistencia\Models\VehiculoConductor;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Repositorios\VehiculoRepositorio;
use Patronato\Persistencia\Repositorios\VinculoRepositorio;
use Patronato\Persistencia\Repositorios\ViviendaRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Vehículos de cada vivienda y las personas autorizadas a conducirlos
 */
class VehiculoService
{
    public const MOTIVO_MINIMO = 10;

    private const POR_PAGINA = 25;

    public function __construct(
        private VehiculoRepositorio $vehiculos,
        private ViviendaRepositorio $viviendas,
        private VinculoRepositorio $vinculos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Vehículos activos o dados de baja para el listado
     *
     * @return LengthAwarePaginator<int, array{id: int, vivienda_id: int, vivienda: string, tipo: string, placa: string, marca: string|null, modelo: string|null, color: string|null, activo: bool, conductores: list<array{id: int, nombre: string}>}>
     */
    public function listado(bool $activos, string $buscar): LengthAwarePaginator
    {
        return $this->vehiculos->paginar($activos, $buscar, self::POR_PAGINA)->through(fn (Vehiculo $vehiculo): array => [
            'id' => $vehiculo->id,
            'vivienda_id' => $vehiculo->vivienda_id,
            'vivienda' => $vehiculo->vivienda->codigo,
            'tipo' => $vehiculo->tipo,
            'placa' => $vehiculo->placa,
            'marca' => $vehiculo->marca,
            'modelo' => $vehiculo->modelo,
            'color' => $vehiculo->color,
            'activo' => $vehiculo->activo,
            'conductores' => array_values($vehiculo->conductores
                ->filter(fn (VehiculoConductor $conductor): bool => $conductor->residente !== null)
                ->map(fn (VehiculoConductor $conductor): array => [
                    'id' => (int) $conductor->residente_id,
                    'nombre' => $this->nombre($conductor->residente),
                ])
                ->all()),
        ]);
    }

    /**
     * Viviendas activas a las que se puede asignar un vehículo
     *
     * @return list<array{id: int, codigo: string}>
     */
    public function viviendasActivas(): array
    {
        return array_values($this->viviendas->activas()
            ->map(fn (Vivienda $vivienda): array => ['id' => $vivienda->id, 'codigo' => $vivienda->codigo])
            ->all());
    }

    /**
     * Residentes de la vivienda que se pueden autorizar como conductores
     *
     * @return list<array{id: int, nombre: string}>
     */
    public function conductoresPosibles(Vivienda $vivienda): array
    {
        return array_values($this->vinculos->residentesVigentesDe($vivienda->id)
            ->map(fn (Residente $residente): array => ['id' => $residente->id, 'nombre' => $this->nombre($residente)])
            ->all());
    }

    /**
     * Forma única de la placa: solo letras y números, en mayúsculas
     *
     * La placa la asigna el gobierno; HAA-1234, HAA 1234 y haa1234 son la misma.
     */
    public function normalizarPlaca(string $placa): string
    {
        return (string) preg_replace('/[^\pL\pN]/u', '', mb_strtoupper($placa));
    }

    /**
     * Motivo por el que no se puede usar la placa, o null si está libre
     */
    public function motivoPlacaNoDisponible(string $placa, ?int $vehiculoId): ?string
    {
        return $this->vehiculos->existePlacaActiva($this->normalizarPlaca($placa), $vehiculoId)
            ? 'Ya hay un vehículo activo con esa placa en el residencial.'
            : null;
    }

    /**
     * Registra un vehículo en una vivienda activa con sus conductores
     *
     * @param  array{vivienda_id: int|string, tipo: string, placa: string, marca?: string|null, modelo?: string|null, color?: string|null, conductores?: array<int, int|string>}  $datos
     *
     * @throws ValidationException
     */
    public function registrar(User $usuario, array $datos): Vehiculo
    {
        $viviendaId = (int) $datos['vivienda_id'];
        $conductores = $this->conductoresValidos($viviendaId, $datos['conductores'] ?? []);

        return $this->guardandoPlaca(fn () => $this->transaccion->ejecutar(function () use ($usuario, $datos, $viviendaId, $conductores) {
            $vehiculo = $this->vehiculos->crear([
                'residencial_id' => $usuario->residencial_id,
                'vivienda_id' => $viviendaId,
                ...$this->campos($datos),
                'activo' => true,
            ]);

            $this->vehiculos->reemplazarConductores($vehiculo, $conductores);

            $this->auditoria->registrar($usuario, 'vehiculos', 'vehiculo_registrado', $vehiculo, [
                'valor_nuevo' => $this->resumen($vehiculo, $conductores),
            ]);

            return $vehiculo;
        }));
    }

    /**
     * Corrige los datos y los conductores de un vehículo activo; la vivienda no cambia
     *
     * @param  array{tipo: string, placa: string, marca?: string|null, modelo?: string|null, color?: string|null, conductores?: array<int, int|string>}  $datos
     *
     * @throws ValidationException
     */
    public function actualizar(User $usuario, Vehiculo $vehiculo, array $datos): void
    {
        $conductores = $this->conductoresValidos($vehiculo->vivienda_id, $datos['conductores'] ?? []);

        $this->guardandoPlaca(fn () => $this->transaccion->ejecutar(function () use ($usuario, $vehiculo, $datos, $conductores) {
            $anterior = $this->resumen($vehiculo, $this->vehiculos->conductoresDe($vehiculo));

            $this->vehiculos->actualizar($vehiculo, $this->campos($datos));
            $this->vehiculos->reemplazarConductores($vehiculo, $conductores);

            $nuevo = $this->resumen($vehiculo, $conductores);

            // Solo los campos que cambiaron, comparados uno por uno porque los conductores son una lista
            $cambiados = array_keys(array_filter($nuevo, fn (mixed $valor, string $campo): bool => $anterior[$campo] !== $valor, ARRAY_FILTER_USE_BOTH));

            if ($cambiados !== []) {
                $this->auditoria->registrar($usuario, 'vehiculos', 'vehiculo_editado', $vehiculo, [
                    'valor_anterior' => array_intersect_key($anterior, array_flip($cambiados)),
                    'valor_nuevo' => array_intersect_key($nuevo, array_flip($cambiados)),
                ]);
            }
        }));
    }

    /**
     * Da de baja un vehículo; su placa queda libre para otro y el registro se conserva
     *
     * @throws ValidationException si ya estaba dado de baja
     */
    public function darDeBaja(User $usuario, Vehiculo $vehiculo, string $motivo): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $vehiculo, $motivo) {
            // Se relee bloqueado para que dos bajas a la vez no se registren ambas
            $actual = $this->vehiculos->bloquearRegistro($vehiculo->id);

            if ($actual === null || ! $actual->activo) {
                throw ValidationException::withMessages(['motivo' => 'El vehículo ya está dado de baja.']);
            }

            $this->vehiculos->actualizar($actual, ['activo' => false]);

            $this->auditoria->registrar($usuario, 'vehiculos', 'vehiculo_dado_de_baja', $actual, [
                'valor_anterior' => ['activo' => true],
                'valor_nuevo' => ['activo' => false],
                'motivo' => trim($motivo),
            ]);
        });
    }

    /**
     * Solo se autorizan residentes que viven hoy en la vivienda del vehículo
     *
     * @param  array<int, int|string>  $ids
     * @return list<int>
     *
     * @throws ValidationException
     */
    private function conductoresValidos(int $viviendaId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        $permitidos = $this->vinculos->residentesVigentesDe($viviendaId)->modelKeys();

        if (array_diff($ids, $permitidos) !== []) {
            throw ValidationException::withMessages(['conductores' => 'Solo se pueden autorizar residentes que viven en la vivienda del vehículo.']);
        }

        return $ids;
    }

    /**
     * La base rechaza dos placas activas iguales aunque se registren al mismo tiempo
     *
     * @template T
     *
     * @param  callable(): T  $operacion
     * @return T
     *
     * @throws ValidationException
     */
    private function guardandoPlaca(callable $operacion): mixed
    {
        try {
            return $operacion();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['placa' => 'Ya hay un vehículo activo con esa placa en el residencial.']);
        }
    }

    /**
     * @param  array{tipo: string, placa: string, marca?: string|null, modelo?: string|null, color?: string|null}  $datos
     * @return array{tipo: string, placa: string, marca: string|null, modelo: string|null, color: string|null}
     */
    private function campos(array $datos): array
    {
        return [
            'tipo' => $datos['tipo'],
            'placa' => $this->normalizarPlaca($datos['placa']),
            'marca' => $this->textoOpcional($datos['marca'] ?? null),
            'modelo' => $this->textoOpcional($datos['modelo'] ?? null),
            'color' => $this->textoOpcional($datos['color'] ?? null),
        ];
    }

    /**
     * @param  list<int>  $conductores
     * @return array{tipo: string, placa: string, marca: string|null, modelo: string|null, color: string|null, conductores: list<int>}
     */
    private function resumen(Vehiculo $vehiculo, array $conductores): array
    {
        return [
            'tipo' => $vehiculo->tipo,
            'placa' => $vehiculo->placa,
            'marca' => $vehiculo->marca,
            'modelo' => $vehiculo->modelo,
            'color' => $vehiculo->color,
            'conductores' => $conductores,
        ];
    }

    private function nombre(?Residente $residente): string
    {
        return $residente === null ? '' : trim("{$residente->nombres} {$residente->apellidos}");
    }

    private function textoOpcional(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }
}
