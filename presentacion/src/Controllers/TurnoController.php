<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\EntregaTurnoService;
use Patronato\Negocio\Services\TurnoService;
use Patronato\Persistencia\Models\Turno;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\ProgramarTurnosRequest;

class TurnoController extends Controller
{
    public function __construct(private TurnoService $turnos) {}

    /**
     * Calendario de turnos por mes o por día
     */
    public function index(Request $request): Response
    {
        $vista = $request->string('vista')->toString() === 'dia' ? 'dia' : 'mes';
        $dia = $this->turnos->diaValido($request->string('dia')->toString() ?: null);
        $mes = $vista === 'dia' ? substr($dia, 0, 7) : $this->turnos->mesValido($request->string('mes')->toString() ?: null);

        return Inertia::render('vigilancia/turnos', [
            'vista' => $vista,
            'mes' => $mes,
            'dia' => $dia,
            'dias' => $vista === 'mes' ? $this->turnos->mes($mes) : [],
            'turnosDelDia' => $vista === 'dia' ? $this->turnos->dia($dia) : [],
            'guardias' => $this->turnos->guardiasActivos(),
            'sugeridos' => Turno::SUGERIDOS,
        ]);
    }

    /**
     * Programa los turnos de un guardia en un rango de fechas
     */
    public function programar(ProgramarTurnosRequest $request): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        /** @var array{guardia_id: int|string, tipo: string, hora_inicio: string, horas: int|string, desde: string, hasta: string, repetir: string, dias?: array<int, int|string>} $datos */
        $datos = $request->validated();

        $cantidad = $this->turnos->programar($usuario, $datos);

        Inertia::flash('toast', ['type' => 'success', 'message' => $cantidad === 1 ? 'Turno programado.' : "{$cantidad} turnos programados."]);

        return back();
    }

    /**
     * Quita un turno que todavía no empieza
     */
    public function quitar(Request $request, Turno $turno): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $this->turnos->quitar($usuario, $turno);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Turno quitado.']);

        return back();
    }

    /**
     * Turnos asignados al guardia de la cuenta y el que puede iniciar, entregar o recibir
     */
    public function misTurnos(Request $request, EntregaTurnoService $entregas): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $guardia = $this->turnos->guardiaDe($usuario);

        abort_if($guardia === null, 403, 'Esta cuenta no está vinculada a un guardia activo');

        return Inertia::render('vigilancia/mis-turnos', [
            'guardia' => $guardia->nombre,
            'turnos' => $this->turnos->deGuardia($guardia),
            ...$entregas->panel($guardia),
        ]);
    }
}
