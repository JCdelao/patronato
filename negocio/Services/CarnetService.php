<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Patronato\Negocio\Credenciales\EstadoCredencial;
use Patronato\Persistencia\Models\Carnet;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\ViviendaResidente;
use Patronato\Persistencia\Repositorios\CarnetRepositorio;
use Patronato\Persistencia\Repositorios\ResidenteRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Carnets de los residentes: emisión con número consecutivo, vigencia y cambios de estado
 *
 * Los estados son los mismos que los de los stickers (patrón State, en negocio/Credenciales).
 */
class CarnetService
{
    public const MOTIVO_MINIMO = 10;

    /**
     * Años que dura un carnet si el secretario no elige otra fecha
     */
    public const ANIOS_VIGENCIA = 1;

    private const POR_PAGINA = 25;

    /**
     * Acción de la bitácora para cada cambio de estado
     */
    private const AUDITORIA = [
        EstadoCredencial::SUSPENDER => 'carnet_suspendido',
        EstadoCredencial::REACTIVAR => 'carnet_reactivado',
        EstadoCredencial::VENCER => 'carnet_vencido',
        EstadoCredencial::DAR_DE_BAJA => 'carnet_dado_de_baja',
    ];

    public function __construct(
        private CarnetRepositorio $carnets,
        private ResidenteRepositorio $residentes,
        private PeriodoService $periodos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Residentes activos con su historial de carnets
     *
     * @return LengthAwarePaginator<int, array{id: int, nombre: string, viviendas: list<string>, carnets: list<array{id: int, numero: string, estado: string, fecha_emision: string, vigente_hasta: string|null, motivo: string|null, acciones: list<string>}>}>
     */
    public function listado(string $buscar): LengthAwarePaginator
    {
        return $this->residentes->paginarActivosConCarnets($buscar, self::POR_PAGINA)->through(fn (Residente $residente): array => [
            'id' => $residente->id,
            'nombre' => trim("{$residente->nombres} {$residente->apellidos}"),
            'viviendas' => array_values($residente->vinculosVigentes
                ->map(fn (ViviendaResidente $vinculo): string => $vinculo->vivienda->codigo)
                ->unique()
                ->all()),
            'carnets' => array_values($residente->carnets->map(fn (Carnet $carnet): array => [
                'id' => $carnet->id,
                'numero' => $carnet->numero,
                'estado' => $carnet->estado,
                'fecha_emision' => $carnet->fecha_emision->toDateString(),
                'vigente_hasta' => $carnet->vigente_hasta?->toDateString(),
                'motivo' => $carnet->motivo,
                'acciones' => EstadoCredencial::de($carnet->estado)->acciones(),
            ])->all()),
        ]);
    }

    /**
     * Fecha que propone el formulario: un año desde hoy
     */
    public function vigenciaPredeterminada(): string
    {
        return $this->periodos->hoy()->addYears(self::ANIOS_VIGENCIA)->toDateString();
    }

    /**
     * Emite el carnet de un residente activo con el siguiente número del residencial
     *
     * @throws ValidationException si el residente está inactivo o ya tiene un carnet vigente
     */
    public function emitir(User $usuario, Residente $residente, string $vigenteHasta): Carnet
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $residente, $vigenteHasta) {
            if (! $residente->activo) {
                throw ValidationException::withMessages(['vigente_hasta' => 'El residente está dado de baja.']);
            }

            if ($this->carnets->bloquearVigenteDe($residente->id) !== null) {
                throw ValidationException::withMessages(['vigente_hasta' => 'El residente ya tiene un carnet; para cambiarlo, renuévalo.']);
            }

            $carnet = $this->nuevo($residente, $vigenteHasta);

            $this->auditoria->registrar($usuario, 'carnets', 'carnet_emitido', $carnet, [
                'valor_nuevo' => ['residente_id' => $residente->id, 'numero' => $carnet->numero, 'vigente_hasta' => $vigenteHasta],
            ]);

            return $carnet;
        });
    }

    /**
     * Sustituye un carnet por uno nuevo con otro número y otra vigencia; así se renueva uno vencido
     *
     * @throws ValidationException
     */
    public function renovar(User $usuario, Carnet $carnet, string $vigenteHasta, string $motivo): Carnet
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $carnet, $vigenteHasta, $motivo) {
            $actual = $this->exigirAccion($carnet, EstadoCredencial::REEMPLAZAR);
            $estadoAnterior = $actual->estado;

            if (! $actual->residente->activo) {
                throw ValidationException::withMessages(['accion' => 'El residente está dado de baja.']);
            }

            $this->carnets->actualizar($actual, ['estado' => Carnet::REEMPLAZADO, 'motivo' => trim($motivo)]);
            $nuevo = $this->nuevo($actual->residente, $vigenteHasta);

            $this->auditoria->registrar($usuario, 'carnets', 'carnet_renovado', $actual, [
                'valor_anterior' => ['numero' => $actual->numero, 'estado' => $estadoAnterior],
                'valor_nuevo' => ['numero' => $nuevo->numero, 'carnet_id' => $nuevo->id, 'vigente_hasta' => $vigenteHasta],
                'motivo' => trim($motivo),
            ]);

            return $nuevo;
        });
    }

    /**
     * Suspende, reactiva o marca como vencido un carnet
     *
     * @throws ValidationException si el estado actual no admite la acción
     */
    public function cambiarEstado(User $usuario, Carnet $carnet, string $accion, string $motivo): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $carnet, $accion, $motivo) {
            $actual = $this->exigirAccion($carnet, $accion);

            // Un carnet cuya vigencia ya pasó no vuelve a estar activo: se renueva
            if ($accion === EstadoCredencial::REACTIVAR && $this->yaVencio($actual)) {
                throw ValidationException::withMessages(['accion' => 'La vigencia del carnet ya terminó; renuévalo.']);
            }

            $this->transicion($usuario, $actual, $accion, $motivo);
        });
    }

    /**
     * Retira definitivamente un carnet
     *
     * @throws ValidationException si ya terminó
     */
    public function darDeBaja(User $usuario, Carnet $carnet, string $motivo): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $carnet, $motivo) {
            $this->transicion($usuario, $this->exigirAccion($carnet, EstadoCredencial::DAR_DE_BAJA), EstadoCredencial::DAR_DE_BAJA, $motivo);
        });
    }

    /**
     * Al dar de baja a un residente, su carnet vigente se da de baja
     *
     * Corre dentro de la transacción de la baja del residente.
     */
    public function darDeBajaDeResidente(User $usuario, Residente $residente): void
    {
        $carnet = $this->carnets->bloquearVigenteDe($residente->id);

        if ($carnet !== null) {
            $this->transicion($usuario, $carnet, EstadoCredencial::DAR_DE_BAJA, 'El residente se dio de baja del padrón.');
        }
    }

    /**
     * Pasa a vencidos los carnets activos cuya vigencia terminó; lo corre la tarea diaria
     *
     * @return int cuántos carnets vencieron
     */
    public function vencerVencidos(): int
    {
        $hoy = $this->periodos->hoy()->toDateString();

        return $this->transaccion->ejecutar(function () use ($hoy) {
            $vencidos = $this->carnets->activosVencidosAntesDe($hoy);

            foreach ($vencidos as $carnet) {
                $motivo = 'Terminó su vigencia el '.$carnet->vigente_hasta?->toDateString().'.';

                $this->carnets->actualizar($carnet, ['estado' => Carnet::VENCIDO, 'motivo' => $motivo]);

                $this->auditoria->registrarSistema($carnet->residencial_id, 'carnets', 'carnet_vencido', $carnet, [
                    'valor_anterior' => ['estado' => Carnet::ACTIVO],
                    'valor_nuevo' => ['estado' => Carnet::VENCIDO],
                    'motivo' => $motivo,
                ]);
            }

            return $vencidos->count();
        });
    }

    /**
     * Relee el carnet bloqueado y comprueba que su estado admita la acción
     *
     * @throws ValidationException
     */
    private function exigirAccion(Carnet $carnet, string $accion): Carnet
    {
        $actual = $this->carnets->bloquearRegistro($carnet->id);

        if ($actual === null || ! EstadoCredencial::de($actual->estado)->permite($accion)) {
            throw ValidationException::withMessages(['accion' => 'El carnet ya no admite ese cambio; actualiza la página.']);
        }

        return $actual;
    }

    /**
     * Lleva el carnet al estado que indica su estado actual para la acción y lo audita
     */
    private function transicion(User $usuario, Carnet $carnet, string $accion, string $motivo): void
    {
        $anterior = $carnet->estado;
        $siguiente = (string) EstadoCredencial::de($anterior)->siguiente($accion);

        $this->carnets->actualizar($carnet, ['estado' => $siguiente, 'motivo' => trim($motivo)]);

        $this->auditoria->registrar($usuario, 'carnets', self::AUDITORIA[$accion], $carnet, [
            'valor_anterior' => ['estado' => $anterior],
            'valor_nuevo' => ['estado' => $siguiente],
            'motivo' => trim($motivo),
        ]);
    }

    /**
     * Crea un carnet activo con el siguiente número; la numeración se bloquea por residencial
     */
    private function nuevo(Residente $residente, string $vigenteHasta): Carnet
    {
        $this->carnets->bloquearNumeracion($residente->residencial_id);

        return $this->carnets->crear([
            'residencial_id' => $residente->residencial_id,
            'residente_id' => $residente->id,
            'numero' => $this->carnets->siguienteNumero($residente->residencial_id),
            'fecha_emision' => $this->periodos->hoy()->toDateString(),
            'vigente_hasta' => $vigenteHasta,
            'estado' => Carnet::ACTIVO,
        ]);
    }

    private function yaVencio(Carnet $carnet): bool
    {
        return $carnet->vigente_hasta !== null
            && CarbonImmutable::parse($carnet->vigente_hasta)->toDateString() < $this->periodos->hoy()->toDateString();
    }
}
