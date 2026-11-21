<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\Turno;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\GuardiaRepositorio;
use Patronato\Persistencia\Repositorios\TurnoRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Calendario de turnos de los guardias, en la hora de Honduras
 *
 * @phpstan-type TurnoDatos array{id: int, guardia_id: int, guardia: string, tipo: string, inicio: string, fin: string, empezo: bool, inicio_real: string|null, entrega: array{recibe: string|null, entregado_en: string, recibido_en: string|null, novedades: string|null}|null}
 */
class TurnoService
{
    /**
     * Días que se pueden programar de una sola vez
     */
    public const MAXIMO_DIAS = 62;

    public const ZONA_LOCAL = 'America/Tegucigalpa';

    // Código de PostgreSQL cuando la restricción de turnos superpuestos rechaza uno
    private const SUPERPOSICION = '23P01';

    public function __construct(
        private TurnoRepositorio $turnos,
        private GuardiaRepositorio $guardias,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Mes del calendario (AAAA-MM); si no es válido, el mes en curso
     */
    public function mesValido(?string $mes): string
    {
        $fecha = is_string($mes) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1
            ? CarbonImmutable::createFromFormat('!Y-m', $mes, self::ZONA_LOCAL)
            : null;

        return ($fecha ?? CarbonImmutable::now(self::ZONA_LOCAL))->format('Y-m');
    }

    /**
     * Día del calendario (AAAA-MM-DD); si no es válido, hoy
     */
    public function diaValido(?string $dia): string
    {
        $fecha = is_string($dia) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $dia, self::ZONA_LOCAL)
            : null;

        return $fecha !== null && $fecha->format('Y-m-d') === $dia ? $dia : $this->hoy()->toDateString();
    }

    /**
     * Turnos del mes agrupados por el día en que empiezan
     *
     * @return list<array{fecha: string, turnos: list<TurnoDatos>}>
     */
    public function mes(string $mes): array
    {
        $desde = CarbonImmutable::createFromFormat('!Y-m', $mes, self::ZONA_LOCAL) ?? $this->hoy()->startOfMonth();
        $hasta = $desde->addMonth();

        $porDia = [];

        foreach ($this->turnos->entre($desde->utc(), $hasta->utc()) as $turno) {
            $fecha = $this->local($turno->inicio)->toDateString();

            if ($fecha >= $desde->toDateString()) {
                $porDia[$fecha][] = $this->datos($turno);
            }
        }

        $dias = [];

        foreach ($desde->toPeriod($hasta->subDay()) as $dia) {
            $fecha = $dia->toDateString();
            $dias[] = ['fecha' => $fecha, 'turnos' => $porDia[$fecha] ?? []];
        }

        return $dias;
    }

    /**
     * Turnos que cubren alguna hora del día, incluidos los que empezaron el día anterior
     *
     * @return list<TurnoDatos>
     */
    public function dia(string $dia): array
    {
        $desde = CarbonImmutable::createFromFormat('!Y-m-d', $dia, self::ZONA_LOCAL) ?? $this->hoy();

        return array_values($this->turnos->entre($desde->utc(), $desde->addDay()->utc())
            ->map(fn (Turno $turno): array => $this->datos($turno))
            ->all());
    }

    /**
     * Turnos del guardia desde hace una semana hasta dos meses adelante
     *
     * @return list<TurnoDatos>
     */
    public function deGuardia(Guardia $guardia): array
    {
        $hoy = $this->hoy();

        return array_values($this->turnos->entre($hoy->subWeek()->utc(), $hoy->addMonths(2)->utc(), $guardia->id)
            ->map(fn (Turno $turno): array => $this->datos($turno))
            ->all());
    }

    /**
     * Guardia activo de la cuenta; sin él no hay turnos que mostrar
     */
    public function guardiaDe(User $usuario): ?Guardia
    {
        return $this->guardias->activoDeUsuario($usuario->id);
    }

    /**
     * Guardias activos que se pueden programar, con su turno habitual
     *
     * @return list<array{id: int, nombre: string, turno: string}>
     */
    public function guardiasActivos(): array
    {
        return array_values($this->guardias->activos()
            ->map(fn (Guardia $guardia): array => ['id' => $guardia->id, 'nombre' => $guardia->nombre, 'turno' => $guardia->turno])
            ->all());
    }

    /**
     * Programa un turno en cada fecha del rango que toca trabajar
     *
     * Las fechas salen de los días de la semana elegidos o van un día de por
     * medio desde la fecha inicial. Si alguno se cruza con otro turno del
     * guardia, no se guarda ninguno.
     *
     * @param  array{guardia_id: int|string, tipo: string, hora_inicio: string, horas: int|string, desde: string, hasta: string, repetir: string, dias?: array<int, int|string>}  $datos
     * @return int cuántos turnos se programaron
     *
     * @throws ValidationException
     */
    public function programar(User $usuario, array $datos): int
    {
        $desde = CarbonImmutable::createFromFormat('!Y-m-d', $datos['desde'], self::ZONA_LOCAL);
        $hasta = CarbonImmutable::createFromFormat('!Y-m-d', $datos['hasta'], self::ZONA_LOCAL);

        if ($desde === null || $hasta === null || $desde->lessThan($this->hoy())) {
            throw ValidationException::withMessages(['desde' => 'Solo se programan turnos desde hoy en adelante.']);
        }

        $rangos = $this->rangos($this->fechas($desde, $hasta, $datos), $datos['hora_inicio'], (int) $datos['horas']);

        try {
            return $this->transaccion->ejecutar(function () use ($usuario, $datos, $rangos) {
                // Se relee bloqueado para que dos programaciones del mismo guardia no se crucen
                $guardia = $this->guardias->bloquearRegistro((int) $datos['guardia_id']);

                if ($guardia === null || ! $guardia->activo) {
                    throw ValidationException::withMessages(['guardia_id' => 'Elige un guardia activo.']);
                }

                $this->exigirSinSuperposicion($guardia, $rangos);

                foreach ($rangos as $rango) {
                    $this->turnos->crear([
                        'residencial_id' => $guardia->residencial_id,
                        'guardia_id' => $guardia->id,
                        'tipo' => $datos['tipo'],
                        'inicio' => $rango['inicio']->utc(),
                        'fin' => $rango['fin']->utc(),
                    ]);
                }

                $this->auditoria->registrar($usuario, 'vigilancia', 'turnos_programados', $guardia, [
                    'valor_nuevo' => [
                        'tipo' => $datos['tipo'],
                        'hora_inicio' => $datos['hora_inicio'],
                        'horas' => (int) $datos['horas'],
                        'fechas' => array_map(fn (array $rango): string => $rango['inicio']->toDateString(), $rangos),
                    ],
                ]);

                return count($rangos);
            });
        } catch (QueryException $e) {
            // La base rechaza el cruce aunque otra programación se guarde al mismo tiempo
            if ($e->getCode() === self::SUPERPOSICION) {
                throw ValidationException::withMessages(['desde' => 'Alguno de los turnos se cruza con otro del guardia.']);
            }

            throw $e;
        }
    }

    /**
     * Quita un turno que todavía no empieza
     *
     * @throws ValidationException si ya empezó
     */
    public function quitar(User $usuario, Turno $turno): void
    {
        if ($turno->inicio_real !== null || $turno->inicio->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw ValidationException::withMessages(['turno' => 'Un turno que ya empezó no se puede quitar.']);
        }

        $this->transaccion->ejecutar(function () use ($usuario, $turno) {
            $this->auditoria->registrar($usuario, 'vigilancia', 'turno_quitado', $turno, [
                'valor_anterior' => [
                    'guardia_id' => $turno->guardia_id,
                    'tipo' => $turno->tipo,
                    'inicio' => $this->local($turno->inicio)->format('Y-m-d H:i'),
                    'fin' => $this->local($turno->fin)->format('Y-m-d H:i'),
                ],
            ]);

            $this->turnos->eliminar($turno);
        });
    }

    /**
     * Fechas del rango en las que el guardia trabaja
     *
     * @param  array{repetir: string, dias?: array<int, int|string>}  $datos
     * @return list<CarbonImmutable>
     *
     * @throws ValidationException
     */
    private function fechas(CarbonImmutable $desde, CarbonImmutable $hasta, array $datos): array
    {
        $dias = array_map('intval', $datos['dias'] ?? []);
        $fechas = [];

        foreach ($desde->toPeriod($hasta) as $indice => $fecha) {
            // Día de por medio: trabaja la fecha inicial, descansa la siguiente y así
            $trabajaHoy = $datos['repetir'] === 'alternos'
                ? $indice % 2 === 0
                : in_array($fecha->dayOfWeekIso, $dias, true);

            if ($trabajaHoy) {
                $fechas[] = CarbonImmutable::parse($fecha->toDateString(), self::ZONA_LOCAL);
            }
        }

        if ($fechas === []) {
            throw ValidationException::withMessages(['dias' => 'Ninguna fecha del rango cae en los días elegidos.']);
        }

        return $fechas;
    }

    /**
     * Inicio y fin de cada turno, en la hora de Honduras
     *
     * @param  list<CarbonImmutable>  $fechas
     * @return list<array{inicio: CarbonImmutable, fin: CarbonImmutable}>
     */
    private function rangos(array $fechas, string $hora, int $horas): array
    {
        [$hh, $mm] = array_map('intval', explode(':', $hora));

        return array_map(function (CarbonImmutable $fecha) use ($hh, $mm, $horas): array {
            $inicio = $fecha->setTime($hh, $mm);

            return ['inicio' => $inicio, 'fin' => $inicio->addHours($horas)];
        }, $fechas);
    }

    /**
     * @param  list<array{inicio: CarbonImmutable, fin: CarbonImmutable}>  $rangos
     *
     * @throws ValidationException con las fechas que se cruzan
     */
    private function exigirSinSuperposicion(Guardia $guardia, array $rangos): void
    {
        $cruces = $this->turnos->superpuestos($guardia->id, array_map(fn (array $rango): array => [
            'inicio' => $rango['inicio']->utc(),
            'fin' => $rango['fin']->utc(),
        ], $rangos));

        if ($cruces->isEmpty()) {
            return;
        }

        $fechas = $cruces->map(fn (Turno $turno): string => $this->local($turno->inicio)->format('d/m/Y H:i'))->take(5)->implode(', ');

        throw ValidationException::withMessages([
            'desde' => "{$guardia->nombre} ya tiene turnos que se cruzan con estos: {$fechas}.",
        ]);
    }

    /**
     * @return TurnoDatos
     */
    public function datos(Turno $turno): array
    {
        $entrega = $turno->entrega;

        return [
            'id' => $turno->id,
            'guardia_id' => $turno->guardia_id,
            'guardia' => $turno->guardia->nombre,
            'tipo' => $turno->tipo,
            'inicio' => $this->local($turno->inicio)->format('Y-m-d H:i'),
            'fin' => $this->local($turno->fin)->format('Y-m-d H:i'),
            'empezo' => $turno->inicio_real !== null || $turno->inicio->lessThanOrEqualTo(CarbonImmutable::now()),
            'inicio_real' => $turno->inicio_real !== null ? $this->local($turno->inicio_real)->format('Y-m-d H:i') : null,
            'entrega' => $entrega !== null ? [
                'recibe' => $entrega->guardiaRecibe?->nombre,
                'entregado_en' => $this->local($entrega->entregado_en)->format('Y-m-d H:i'),
                'recibido_en' => $entrega->recibido_en !== null ? $this->local($entrega->recibido_en)->format('Y-m-d H:i') : null,
                'novedades' => $entrega->novedades,
            ] : null,
        ];
    }

    private function local(DateTimeInterface $fecha): CarbonImmutable
    {
        return CarbonImmutable::instance($fecha)->setTimezone(self::ZONA_LOCAL);
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONA_LOCAL)->startOfDay();
    }
}
