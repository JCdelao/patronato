<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Patronato\Negocio\Services\EntregaTurnoService;
use Patronato\Negocio\Services\TurnoService;
use Patronato\Persistencia\Models\EntregaTurno;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\Turno;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\EntregarTurnoRequest;

class EntregaTurnoController extends Controller
{
    public function __construct(
        private EntregaTurnoService $entregas,
        private TurnoService $turnos,
    ) {}

    /**
     * El guardia empieza su turno
     */
    public function iniciar(Request $request, Turno $turno): RedirectResponse
    {
        $guardia = $this->guardia($request);

        abort_unless($turno->guardia_id === $guardia->id, 403, 'Este turno no es tuyo');

        $this->entregas->iniciar($this->usuario($request), $guardia, $turno);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Turno iniciado.']);

        return back();
    }

    /**
     * El guardia termina su turno con las novedades y, si quiere, elige quién lo releva
     */
    public function entregar(EntregarTurnoRequest $request, Turno $turno): RedirectResponse
    {
        $guardia = $this->guardia($request);

        abort_unless($turno->guardia_id === $guardia->id, 403, 'Este turno no es tuyo');

        $this->entregas->entregar(
            $this->usuario($request),
            $guardia,
            $turno,
            $request->filled('guardia_recibe_id') ? $request->integer('guardia_recibe_id') : null,
            $request->string('novedades')->toString(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Turno terminado.']);

        return back();
    }

    /**
     * El guardia que recibe confirma la entrega
     */
    public function recibir(Request $request, EntregaTurno $entrega): RedirectResponse
    {
        $guardia = $this->guardia($request);

        abort_unless($entrega->guardia_recibe_id === $guardia->id, 403, 'Esta entrega no es para ti');

        $inicio = $this->entregas->recibir($this->usuario($request), $guardia, $entrega);

        Inertia::flash('toast', ['type' => 'success', 'message' => $inicio ? 'Turno recibido e iniciado.' : 'Turno recibido.']);

        return back();
    }

    private function guardia(Request $request): Guardia
    {
        $guardia = $this->turnos->guardiaDe($this->usuario($request));

        abort_if($guardia === null, 403, 'Esta cuenta no está vinculada a un guardia activo');

        return $guardia;
    }

    private function usuario(Request $request): User
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return $usuario;
    }
}
