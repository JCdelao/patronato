<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Patronato\Negocio\Services\TipoIncidenciaService;
use Patronato\Persistencia\Models\TipoIncidencia;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\GuardarTipoIncidenciaRequest;

class TipoIncidenciaController extends Controller
{
    public function __construct(private TipoIncidenciaService $tipos) {}

    /**
     * Agrega un tipo al catálogo
     */
    public function store(GuardarTipoIncidenciaRequest $request): RedirectResponse
    {
        $this->tipos->registrar($this->usuario($request), $request->string('nombre')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tipo de incidencia agregado.']);

        return back();
    }

    /**
     * Cambia el nombre del tipo
     */
    public function update(GuardarTipoIncidenciaRequest $request, TipoIncidencia $tipo): RedirectResponse
    {
        $this->tipos->renombrar($this->usuario($request), $tipo, $request->string('nombre')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tipo de incidencia actualizado.']);

        return back();
    }

    /**
     * El tipo deja de elegirse en incidencias nuevas
     */
    public function desactivar(Request $request, TipoIncidencia $tipo): RedirectResponse
    {
        $this->tipos->cambiarActivo($this->usuario($request), $tipo, false);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$tipo->nombre} ya no se puede elegir."]);

        return back();
    }

    /**
     * El tipo se vuelve a elegir en incidencias nuevas
     */
    public function activar(Request $request, TipoIncidencia $tipo): RedirectResponse
    {
        $this->tipos->cambiarActivo($this->usuario($request), $tipo, true);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$tipo->nombre} se puede elegir otra vez."]);

        return back();
    }

    private function usuario(Request $request): User
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return $usuario;
    }
}
