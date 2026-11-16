<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Patronato\Negocio\Services\GeneracionCargosService;

class GeneracionCargosController extends Controller
{
    /**
     * Genera ahora los cargos del mes en curso que falten
     */
    public function generar(Request $request, GeneracionCargosService $generacion): RedirectResponse
    {
        $creados = $generacion->generarAhora($request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $creados === 0
                ? 'Los cargos del mes ya estaban generados.'
                : "Se generaron {$creados} cargos.",
        ]);

        return back();
    }

    /**
     * Cambia el día del mes en que se generan los cargos
     */
    public function dia(Request $request, GeneracionCargosService $generacion): RedirectResponse
    {
        /** @var array{dia: string} $datos */
        $datos = $request->validate(
            ['dia' => ['required', 'integer', 'min:1', 'max:28']],
            ['dia.max' => 'Elige un día del 1 al 28, para que exista en todos los meses.', 'dia.min' => 'Elige un día del 1 al 28.'],
        );

        $generacion->cambiarDia($request->user(), (int) $datos['dia']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Los cargos se generarán el día {$datos['dia']} de cada mes."]);

        return back();
    }
}
