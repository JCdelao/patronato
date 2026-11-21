<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\EntregaTurno;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\Turno;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\EntregaTurnoRepositorio;
use Patronato\Persistencia\Repositorios\GuardiaRepositorio;
use Patronato\Persistencia\Repositorios\TurnoRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Inicio y entrega de turnos entre guardias, con las novedades y la confirmación de quien recibe
 *
 * @phpstan-import-type TurnoDatos from TurnoService
 */
class EntregaTurnoService
{
    /**
     * Minutos antes de la hora programada en que el guardia puede iniciar su turno
     */
    public const MINUTOS_ANTES = 60;

    // Horas alrededor del fin del turno en que se busca al guardia que llega
    private const HORAS_RELEVO = 3;

    public function __construct(
        private TurnoRepositorio $turnos,
        private EntregaTurnoRepositorio $entregas,
        private GuardiaRepositorio $guardias,
        private TurnoService $calendario,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Lo que el guardia puede hacer ahora: recibir una entrega, iniciar su turno o entregarlo
     *
     * @return array{pendiente: array{id: int, entrega: string, entregado_en: string, novedades: string|null, tipo: string, inicio: string, fin: string}|null, actual: TurnoDatos|null, receptores: list<array{id: int, nombre: string}>, sugerido: int|null}
     */
    public function panel(Guardia $guardia): array
    {
        $pendiente = $this->entregas->pendienteDe($guardia->id);
        $actual = $this->turnoActual($guardia);

        return [
            'pendiente' => $pendiente !== null ? [
                'id' => $pendiente->id,
                'entrega' => $pendiente->guardiaEntrega->nombre,
                'entregado_en' => $this->local($pendiente->entregado_en)->format('Y-m-d H:i'),
                'novedades' => $pendiente->novedades,
                'tipo' => $pendiente->turno->tipo,
                'inicio' => $this->local($pendiente->turno->inicio)->format('Y-m-d H:i'),
                'fin' => $this->local($pendiente->turno->fin)->format('Y-m-d H:i'),
            ] : null,
            'actual' => $actual !== null ? $this->calendario->datos($actual) : null,
            'receptores' => array_values($this->guardias->activos()
                ->reject(fn (Guardia $otro): bool => $otro->id === $guardia->id)
                ->map(fn (Guardia $otro): array => ['id' => $otro->id, 'nombre' => $otro->nombre])
                ->all()),
            'sugerido' => $actual?->inicio_real !== null ? $this->relevo($actual) : null,
        ];
    }

    /**
     * Marca la hora en que el guardia empieza su turno
     *
     * @throws ValidationException si todavía no es hora, ya terminó, ya empezó o falta entregar otro
     */
    public function iniciar(User $usuario, Guardia $guardia, Turno $turno): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $guardia, $turno) {
            $turno = $this->turnos->bloquearRegistro($turno->id) ?? $turno;

            $this->exigirQueSePuedaIniciar($guardia, $turno);
            $this->marcarInicio($usuario, $turno);
        });
    }

    /**
     * Termina el turno en curso con las novedades; el guardia que lo releva es opcional
     *
     * @throws ValidationException si no ha empezado, ya se entregó o se elige a sí mismo
     */
    public function entregar(User $usuario, Guardia $guardia, Turno $turno, ?int $recibeId, ?string $novedades): void
    {
        if ($recibeId === $guardia->id) {
            throw ValidationException::withMessages(['guardia_recibe_id' => 'Elige al guardia que recibe el turno, no a ti.']);
        }

        $novedades = trim((string) $novedades) ?: null;

        try {
            $this->transaccion->ejecutar(function () use ($usuario, $guardia, $turno, $recibeId, $novedades) {
                // Se relee bloqueado para que el turno no se entregue dos veces
                $turno = $this->turnos->bloquearRegistro($turno->id) ?? $turno;

                if ($turno->inicio_real === null) {
                    throw ValidationException::withMessages(['turno' => 'Inicia el turno antes de entregarlo.']);
                }

                if ($turno->entrega()->exists()) {
                    throw ValidationException::withMessages(['turno' => 'Este turno ya se entregó.']);
                }

                $entrega = $this->entregas->crear([
                    'residencial_id' => $turno->residencial_id,
                    'turno_id' => $turno->id,
                    'guardia_entrega_id' => $guardia->id,
                    'guardia_recibe_id' => $recibeId,
                    'entregado_en' => CarbonImmutable::now()->utc(),
                    'novedades' => $novedades,
                ]);

                $this->auditoria->registrar($usuario, 'vigilancia', 'turno_entregado', $entrega, [
                    'valor_nuevo' => ['turno_id' => $turno->id, 'guardia_recibe_id' => $recibeId, 'novedades' => $novedades],
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Otra entrega del mismo turno se guardó al mismo tiempo
            throw ValidationException::withMessages(['turno' => 'Este turno ya se entregó.']);
        }
    }

    /**
     * Confirma que el guardia recibió el turno; si el suyo está por empezar, lo inicia
     *
     * @return bool si se inició el turno del guardia que recibe
     *
     * @throws ValidationException si ya la confirmó
     */
    public function recibir(User $usuario, Guardia $guardia, EntregaTurno $entrega): bool
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $guardia, $entrega) {
            $entrega = $this->entregas->bloquearRegistro($entrega->id) ?? $entrega;

            if ($entrega->recibido_en !== null) {
                throw ValidationException::withMessages(['entrega' => 'Ya confirmaste que recibiste este turno.']);
            }

            $this->entregas->actualizar($entrega, ['recibido_en' => CarbonImmutable::now()->utc()]);

            $this->auditoria->registrar($usuario, 'vigilancia', 'turno_recibido', $entrega, [
                'valor_nuevo' => ['recibido_en' => $this->local($entrega->recibido_en ?? CarbonImmutable::now())->format('Y-m-d H:i')],
            ]);

            $propio = $this->turnoActual($guardia);

            if ($propio === null || $propio->inicio_real !== null) {
                return false;
            }

            $this->marcarInicio($usuario, $this->turnos->bloquearRegistro($propio->id) ?? $propio);

            return true;
        });
    }

    /**
     * El turno que el guardia tiene en curso o, si no, el que puede iniciar
     */
    private function turnoActual(Guardia $guardia): ?Turno
    {
        $ahora = CarbonImmutable::now()->utc();

        return $this->turnos->enCurso($guardia->id)
            ?? $this->turnos->porIniciar($guardia->id, $ahora, $ahora->addMinutes(self::MINUTOS_ANTES));
    }

    /**
     * @throws ValidationException
     */
    private function exigirQueSePuedaIniciar(Guardia $guardia, Turno $turno): void
    {
        $ahora = CarbonImmutable::now();

        $error = match (true) {
            $turno->inicio_real !== null => 'Este turno ya empezó.',
            $turno->fin->lessThanOrEqualTo($ahora) => 'Este turno ya terminó.',
            $turno->inicio->greaterThan($ahora->addMinutes(self::MINUTOS_ANTES)) => 'El turno se puede iniciar desde una hora antes.',
            $this->turnos->enCurso($guardia->id) !== null => 'Entrega primero el turno que tienes en curso.',
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['turno' => $error]);
        }
    }

    private function marcarInicio(User $usuario, Turno $turno): void
    {
        $this->turnos->actualizar($turno, ['inicio_real' => CarbonImmutable::now()->utc()]);

        $this->auditoria->registrar($usuario, 'vigilancia', 'turno_iniciado', $turno, [
            'valor_nuevo' => ['inicio_real' => $this->local($turno->inicio_real ?? CarbonImmutable::now())->format('Y-m-d H:i')],
        ]);
    }

    /**
     * Guardia cuyo turno empieza más cerca del fin del turno en curso
     */
    private function relevo(Turno $turno): ?int
    {
        $fin = CarbonImmutable::instance($turno->fin);

        return $this->turnos->queEmpiezanEntre($fin->subHours(self::HORAS_RELEVO), $fin->addHours(self::HORAS_RELEVO), $turno->guardia_id)
            ->filter(fn (Turno $otro): bool => $otro->guardia->activo)
            ->sortBy(fn (Turno $otro): float => abs($otro->inicio->diffInMinutes($fin)))
            ->first()?->guardia_id;
    }

    private function local(DateTimeInterface $fecha): CarbonImmutable
    {
        return CarbonImmutable::instance($fecha)->setTimezone(TurnoService::ZONA_LOCAL);
    }
}
