<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Patronato\Negocio\Services\TipoViviendaService;
use Patronato\Persistencia\Models\TipoVivienda;
use Patronato\Presentacion\Requests\GuardarTipoViviendaRequest;

class TipoViviendaController extends Controller
{
    /**
     * Registra un tipo de vivienda con su cuota mensual
     */
    public function store(GuardarTipoViviendaRequest $request, TipoViviendaService $tipos): RedirectResponse
    {
        /** @var array{nombre: string, monto: string, vigente_desde: string} $datos */
        $datos = $request->validated();

        $tipos->registrar($request->user(), $datos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tipo de vivienda registrado.']);

        return back();
    }

    /**
     * Actualiza un tipo de vivienda
     */
    public function update(GuardarTipoViviendaRequest $request, TipoViviendaService $tipos, TipoVivienda $tipo): RedirectResponse
    {
        abort_unless($tipo->activo, 404);

        /** @var array{nombre: string, monto: string, vigente_desde: string} $datos */
        $datos = $request->validated();

        $tipos->actualizar($request->user(), $tipo, $datos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tipo de vivienda actualizado.']);

        return back();
    }

    /**
     * Desactiva un tipo de vivienda
     */
    public function desactivar(Request $request, TipoViviendaService $tipos, TipoVivienda $tipo): RedirectResponse
    {
        abort_unless($tipo->activo, 404);

        $tipos->desactivar($request->user(), $tipo);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$tipo->nombre} quedó inactivo."]);

        return back();
    }

    /**
     * Reactiva un tipo de vivienda
     */
    public function reactivar(Request $request, TipoViviendaService $tipos, TipoVivienda $tipo): RedirectResponse
    {
        abort_if($tipo->activo, 404);

        $tipos->reactivar($request->user(), $tipo);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$tipo->nombre} reactivado."]);

        return back();
    }

    /**
     * Elimina un tipo de vivienda que ninguna vivienda usa
     */
    public function destroy(Request $request, TipoViviendaService $tipos, TipoVivienda $tipo): RedirectResponse
    {
        abort_unless($tipo->activo, 404);

        try {
            $tipos->eliminar($request->user(), $tipo);
        } catch (ValidationException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tipo de vivienda eliminado.']);

        return back();
    }
}
