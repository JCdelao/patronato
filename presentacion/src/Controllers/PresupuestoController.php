<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\PresupuestoService;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\FiltrarPresupuestoRequest;
use Patronato\Presentacion\Requests\GuardarPresupuestoRequest;

class PresupuestoController extends Controller
{
    public function __construct(private PresupuestoService $presupuestos) {}

    /**
     * Compara lo presupuestado con lo ejecutado en el año elegido
     */
    public function index(FiltrarPresupuestoRequest $request): Response
    {
        return Inertia::render('finanzas/presupuesto', [
            'anios' => $this->presupuestos->anios(),
            'umbral' => PresupuestoService::UMBRAL_ALERTA,
            'comparacion' => $this->presupuestos->comparacion($request->anio()),
        ]);
    }

    /**
     * Guarda el presupuesto del año por categoría
     */
    public function guardar(GuardarPresupuestoRequest $request): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $anio = $request->integer('anio');

        $cambios = $this->presupuestos->guardar($usuario, $anio, $request->montos());

        Inertia::flash('toast', ['type' => 'success', 'message' => $cambios === 0
            ? 'No había cambios que guardar.'
            : "Presupuesto {$anio} guardado."]);

        return to_route('finanzas.presupuesto.index', ['anio' => $anio]);
    }
}
