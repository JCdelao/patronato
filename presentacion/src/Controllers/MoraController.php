<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Patronato\Negocio\Services\MoraService;

class MoraController extends Controller
{
    /**
     * Cambia los días de gracia y los meses de tolerancia de la mora
     */
    public function update(Request $request, MoraService $mora): RedirectResponse
    {
        /** @var array{dias_gracia: string, meses_tolerancia: string} $datos */
        $datos = $request->validate([
            'dias_gracia' => ['required', 'integer', 'min:0', 'max:28'],
            'meses_tolerancia' => ['required', 'integer', 'min:0', 'max:12'],
        ], [
            'dias_gracia.max' => 'Elige de 0 a 28 días, para que el mes venza dentro del mismo mes.',
            'dias_gracia.min' => 'Elige de 0 a 28 días.',
            'meses_tolerancia.max' => 'Elige de 0 a 12 meses.',
            'meses_tolerancia.min' => 'Elige de 0 a 12 meses.',
        ]);

        $mora->cambiarRegla($request->user(), [
            'dias_gracia' => (int) $datos['dias_gracia'],
            'meses_tolerancia' => (int) $datos['meses_tolerancia'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Regla de mora guardada.']);

        return back();
    }
}
