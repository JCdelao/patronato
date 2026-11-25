<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Patronato\Negocio\Services\ExcepcionAccesoService;
use Patronato\Negocio\Services\ReglaAccesoPagoService;

class ExcepcionAccesoController extends Controller
{
    public function store(Request $request, ReglaAccesoPagoService $regla, ExcepcionAccesoService $excepciones): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($regla->puedeConfigurar($usuario), 403);

        $request->validate([
            'vivienda_id' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'max:1000'],
            'vence_en' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);

        $excepciones->conceder($usuario, $request->integer('vivienda_id'), trim($request->string('motivo')->toString()), $request->string('vence_en')->toString());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Excepción temporal registrada.']);

        return back();
    }
}
