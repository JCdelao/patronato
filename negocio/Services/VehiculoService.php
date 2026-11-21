<?php

namespace Patronato\Negocio\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Patronato\Negocio\Credenciales\EstadoCredencial;
use Patronato\Negocio\Eventos\VehiculoDadoDeBaja;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\Sticker;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vehiculo;
use Patronato\Persistencia\Models\VehiculoConductor;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Repositorios\VehiculoRepositorio;
use Patronato\Persistencia\Repositorios\VinculoRepositorio;
use Patronato\Persistencia\Repositorios\ViviendaRepositorio;
use Patronato\Persistencia\Transaccion;
use Throwable;

/**
 * Vehículos de cada vivienda y las personas autorizadas a conducirlos
 */
class VehiculoService
{
    public const MOTIVO_MINIMO = 10;

    /**
     * Los comprobantes de los vehículos sin placa van a un disco privado
     */
    private const DISCO = 'local';

    private const POR_PAGINA = 25;

    public function __construct(
        private VehiculoRepositorio $vehiculos,
        private ViviendaRepositorio $viviendas,
        private VinculoRepositorio $vinculos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
        private Dispatcher $eventos,
        private PeriodoService $periodos,
    ) {}

    /**
     * Vehículos activos o dados de baja para el listado
     *
     * @return LengthAwarePaginator<int, array{id: int, vivienda_id: int, vivienda: string, tipo: string, placa: string|null, comprobante: array{tipo: string|null, descripcion: string|null, numero: string, vence: string|null, tiene_archivo: bool}|null, comprobante_vencido: bool, autorizado: bool, marca: string|null, modelo: string|null, color: string|null, activo: bool, stickers: list<array{id: int, numero: string, estado: string, fecha_emision: string, motivo: string|null, acciones: list<string>}>, conductores: list<array{id: int, nombre: string}>}>
     */
    public function listado(bool $activos, string $buscar): LengthAwarePaginator
    {
        $hoy = $this->periodos->hoy()->toDateString();

        return $this->vehiculos->paginar($activos, $buscar, self::POR_PAGINA)->through(fn (Vehiculo $vehiculo): array => [
            'id' => $vehiculo->id,
            'vivienda_id' => $vehiculo->vivienda_id,
            'vivienda' => $vehiculo->vivienda->codigo,
            'tipo' => $vehiculo->tipo,
            'placa' => $vehiculo->placa,
            // Se conserva como historial aunque ya tenga placa
            'comprobante' => $vehiculo->comprobante_numero === null ? null : [
                'tipo' => $vehiculo->comprobante_tipo,
                'descripcion' => $vehiculo->comprobante_descripcion,
                'numero' => $vehiculo->comprobante_numero,
                'vence' => $vehiculo->comprobante_vence?->toDateString(),
                'tiene_archivo' => $vehiculo->comprobante_path !== null,
            ],
            'comprobante_vencido' => $vehiculo->comprobanteVencido($hoy),
            'autorizado' => $vehiculo->autorizado($hoy),
            'marca' => $vehiculo->marca,
            'modelo' => $vehiculo->modelo,
            'color' => $vehiculo->color,
            'activo' => $vehiculo->activo,
            'stickers' => array_values($vehiculo->stickers->map(fn (Sticker $sticker): array => [
                'id' => $sticker->id,
                'numero' => $sticker->numero,
                'estado' => $sticker->estado,
                'fecha_emision' => $sticker->fecha_emision->toDateString(),
                'motivo' => $sticker->motivo,
                'acciones' => EstadoCredencial::de($sticker->estado)->acciones(),
            ])->all()),
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
     * El número de comprobante se compara igual que la placa: solo letras y números, en mayúsculas
     */
    public function normalizarComprobante(string $numero): string
    {
        return $this->normalizarPlaca($numero);
    }

    /**
     * Motivo por el que no se puede usar el número de comprobante, o null si está libre
     */
    public function motivoComprobanteNoDisponible(string $numero, ?int $vehiculoId): ?string
    {
        return $this->vehiculos->existeComprobanteActivo($this->normalizarComprobante($numero), $vehiculoId)
            ? 'Ya hay un vehículo activo con ese número de comprobante en el residencial.'
            : null;
    }

    /**
     * Fecha de hoy en la hora local, para validar el vencimiento del comprobante
     */
    public function hoy(): string
    {
        return $this->periodos->hoy()->toDateString();
    }

    /**
     * Ruta del archivo del comprobante, o null si no tiene o el archivo no existe
     */
    public function rutaComprobante(Vehiculo $vehiculo): ?string
    {
        $disco = Storage::disk(self::DISCO);

        return $vehiculo->comprobante_path !== null && $disco->exists($vehiculo->comprobante_path)
            ? $disco->path($vehiculo->comprobante_path)
            : null;
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
     * Sin placa todavía, lo identifica un comprobante con su archivo.
     *
     * @param  array{vivienda_id: int|string, tipo: string, sin_placa?: bool|string|null, placa?: string|null, comprobante_tipo?: string|null, comprobante_descripcion?: string|null, comprobante_numero?: string|null, comprobante_vence?: string|null, marca?: string|null, modelo?: string|null, color?: string|null, conductores?: array<int, int|string>}  $datos
     *
     * @throws ValidationException
     */
    public function registrar(User $usuario, array $datos, ?UploadedFile $comprobante = null): Vehiculo
    {
        $viviendaId = (int) $datos['vivienda_id'];
        $sinPlaca = $this->sinPlaca($datos);
        $conductores = $this->conductoresValidos($viviendaId, $datos['conductores'] ?? []);
        $ruta = $sinPlaca ? $this->guardarArchivo($usuario, $comprobante) : null;

        return $this->limpiandoSiFalla($ruta, fn () => $this->guardandoIdentificacion($sinPlaca, fn () => $this->transaccion->ejecutar(function () use ($usuario, $datos, $viviendaId, $conductores, $sinPlaca, $ruta) {
            $vehiculo = $this->vehiculos->crear([
                'residencial_id' => $usuario->residencial_id,
                'vivienda_id' => $viviendaId,
                ...$this->campos($datos),
                ...($sinPlaca ? $this->camposComprobante($datos, $ruta) : ['placa' => $this->normalizarPlaca($datos['placa'] ?? '')]),
                'activo' => true,
            ]);

            $this->vehiculos->reemplazarConductores($vehiculo, $conductores);

            $this->auditoria->registrar($usuario, 'vehiculos', 'vehiculo_registrado', $vehiculo, [
                'valor_nuevo' => $this->resumen($vehiculo, $conductores),
            ]);

            return $vehiculo;
        })));
    }

    /**
     * Corrige los datos y los conductores de un vehículo activo; la vivienda no cambia
     *
     * Un vehículo sin placa puede renovar su comprobante o recibir su placa; al recibirla, el
     * comprobante se conserva como historial. Uno con placa no vuelve a quedar sin ella.
     *
     * @param  array{tipo: string, sin_placa?: bool|string|null, placa?: string|null, comprobante_tipo?: string|null, comprobante_descripcion?: string|null, comprobante_numero?: string|null, comprobante_vence?: string|null, marca?: string|null, modelo?: string|null, color?: string|null, conductores?: array<int, int|string>}  $datos
     *
     * @throws ValidationException
     */
    public function actualizar(User $usuario, Vehiculo $vehiculo, array $datos, ?UploadedFile $comprobante = null): void
    {
        $sinPlaca = ! $vehiculo->tienePlaca() && $this->sinPlaca($datos);
        $conductores = $this->conductoresValidos($vehiculo->vivienda_id, $datos['conductores'] ?? []);
        $rutaNueva = $sinPlaca && $comprobante !== null ? $this->guardarArchivo($usuario, $comprobante) : null;
        $rutaAnterior = $vehiculo->comprobante_path;

        $this->limpiandoSiFalla($rutaNueva, fn () => $this->guardandoIdentificacion($sinPlaca, fn () => $this->transaccion->ejecutar(function () use ($usuario, $vehiculo, $datos, $conductores, $sinPlaca, $rutaNueva) {
            $anterior = $this->resumen($vehiculo, $this->vehiculos->conductoresDe($vehiculo));

            $this->vehiculos->actualizar($vehiculo, [
                ...$this->campos($datos),
                ...($sinPlaca
                    ? $this->camposComprobante($datos, $rutaNueva ?? $vehiculo->comprobante_path)
                    : ['placa' => $this->normalizarPlaca($datos['placa'] ?? '')]),
            ]);
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
        })));

        // Un comprobante renovado reemplaza el archivo anterior
        if ($rutaNueva !== null && $rutaAnterior !== null && $rutaAnterior !== $rutaNueva) {
            Storage::disk(self::DISCO)->delete($rutaAnterior);
        }
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

            // Su sticker se da de baja dentro de esta misma transacción
            $this->eventos->dispatch(new VehiculoDadoDeBaja($actual, $usuario, trim($motivo)));
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
     * La base rechaza dos placas o dos comprobantes activos iguales aunque se registren al mismo tiempo
     *
     * @template T
     *
     * @param  callable(): T  $operacion
     * @return T
     *
     * @throws ValidationException
     */
    private function guardandoIdentificacion(bool $sinPlaca, callable $operacion): mixed
    {
        try {
            return $operacion();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages($sinPlaca
                ? ['comprobante_numero' => 'Ya hay un vehículo activo con ese número de comprobante en el residencial.']
                : ['placa' => 'Ya hay un vehículo activo con esa placa en el residencial.']);
        }
    }

    /**
     * Si no se guarda el vehículo, el archivo subido no debe quedar huérfano
     *
     * @template T
     *
     * @param  callable(): T  $operacion
     * @return T
     */
    private function limpiandoSiFalla(?string $ruta, callable $operacion): mixed
    {
        try {
            return $operacion();
        } catch (Throwable $e) {
            if ($ruta !== null) {
                Storage::disk(self::DISCO)->delete($ruta);
            }

            throw $e;
        }
    }

    /**
     * @throws ValidationException si falta el archivo del comprobante
     */
    private function guardarArchivo(User $usuario, ?UploadedFile $comprobante): string
    {
        $ruta = $comprobante?->store("comprobantes-vehiculos/{$usuario->residencial_id}", self::DISCO);

        if (! is_string($ruta)) {
            throw ValidationException::withMessages(['comprobante_archivo' => 'Adjunta la foto o el PDF del comprobante.']);
        }

        return $ruta;
    }

    /**
     * @param  array{sin_placa?: bool|string|null}  $datos
     */
    private function sinPlaca(array $datos): bool
    {
        return filter_var($datos['sin_placa'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Datos del vehículo que no dependen de si tiene placa
     *
     * @param  array{tipo: string, marca?: string|null, modelo?: string|null, color?: string|null}  $datos
     * @return array{tipo: string, marca: string|null, modelo: string|null, color: string|null}
     */
    private function campos(array $datos): array
    {
        return [
            'tipo' => $datos['tipo'],
            'marca' => $this->textoOpcional($datos['marca'] ?? null),
            'modelo' => $this->textoOpcional($datos['modelo'] ?? null),
            'color' => $this->textoOpcional($datos['color'] ?? null),
        ];
    }

    /**
     * Comprobante de un vehículo sin placa; la descripción solo se guarda para el tipo "otro"
     *
     * @param  array{comprobante_tipo?: string|null, comprobante_descripcion?: string|null, comprobante_numero?: string|null, comprobante_vence?: string|null}  $datos
     * @return array{placa: null, comprobante_tipo: string, comprobante_descripcion: string|null, comprobante_numero: string, comprobante_vence: string, comprobante_path: string|null}
     */
    private function camposComprobante(array $datos, ?string $ruta): array
    {
        $tipo = $datos['comprobante_tipo'] ?? '';

        return [
            'placa' => null,
            'comprobante_tipo' => $tipo,
            'comprobante_descripcion' => $tipo === 'otro' ? $this->textoOpcional($datos['comprobante_descripcion'] ?? null) : null,
            'comprobante_numero' => $this->normalizarComprobante($datos['comprobante_numero'] ?? ''),
            'comprobante_vence' => $datos['comprobante_vence'] ?? '',
            'comprobante_path' => $ruta,
        ];
    }

    /**
     * @param  list<int>  $conductores
     * @return array{tipo: string, placa: string|null, comprobante_tipo: string|null, comprobante_numero: string|null, comprobante_vence: string|null, marca: string|null, modelo: string|null, color: string|null, conductores: list<int>}
     */
    private function resumen(Vehiculo $vehiculo, array $conductores): array
    {
        return [
            'tipo' => $vehiculo->tipo,
            'placa' => $vehiculo->placa,
            'comprobante_tipo' => $vehiculo->comprobante_tipo,
            'comprobante_numero' => $vehiculo->comprobante_numero,
            'comprobante_vence' => $vehiculo->comprobante_vence?->toDateString(),
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
