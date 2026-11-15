<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Patronato\Negocio\Services\TarifaEspecialService;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Presentacion\Requests\GuardarTarifaEspecialRequest;

class TarifaEspecialController extends Controller
{
    /**
     * Asigna o cambia la cuota especial de una vivienda
     */
    public function store(GuardarTarifaEspecialRequest $request, TarifaEspecialService $especiales): RedirectResponse
    {
        /** @var array{vivienda_id: string, monto: string, motivo: string, vigente_desde: string} $datos */
        $datos = $request->validated();

        $especiales->asignar($request->user(), $datos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cuota especial guardada.']);

        return back();
    }

    /**
     * Quita la cuota especial de una vivienda desde un mes
     */
    public function quitar(Request $request, TarifaEspecialService $especiales, Vivienda $vivienda): RedirectResponse
    {
        /** @var array{vigente_desde: string} $datos */
        $datos = $request->validate(['vigente_desde' => ['required', 'date_format:Y-m']]);

        $especiales->quitar($request->user(), $vivienda, $datos['vigente_desde']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$vivienda->codigo} volverá a pagar la cuota de su tipo."]);

        return back();
    }
}
