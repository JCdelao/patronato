<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Patronato\Negocio\Services\ReglaAccesoPagoService;

class ReglaAccesoPagoController extends Controller
{
    public function update(Request $request, ReglaAccesoPagoService $regla): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($regla->puedeConfigurar($usuario), 403);

        $request->validate([
            'acceso_depende_pago' => ['required', 'boolean'],
            'meses_tolerancia_acceso' => ['required', 'integer', 'min:0', 'max:12'],
            'pendiente_cuenta_al_dia' => ['required', 'boolean'],
        ]);

        $regla->guardar($usuario, [
            'acceso_depende_pago' => $request->boolean('acceso_depende_pago'),
            'meses_tolerancia_acceso' => $request->integer('meses_tolerancia_acceso'),
            'pendiente_cuenta_al_dia' => $request->boolean('pendiente_cuenta_al_dia'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Regla de acceso guardada.']);

        return back();
    }
}
