<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\CierreCajaService;
use Patronato\Persistencia\Models\Caja;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\CerrarCajaRequest;

class CajaController extends Controller
{
    public function __construct(private CierreCajaService $cierres) {}

    /**
     * Lista las cajas abiertas por cobrador y las últimas cerradas
     */
    public function index(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('finanzas/cajas', $this->cierres->resumen($usuario));
    }

    /**
     * Muestra los cobros de la caja y el formulario de cierre
     */
    public function show(Request $request, Caja $caja): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('finanzas/caja', [
            'caja' => $this->cierres->detalle($usuario, $caja),
        ]);
    }

    /**
     * Cierra la caja con el monto entregado
     */
    public function cerrar(CerrarCajaRequest $request, Caja $caja): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $this->cierres->cerrar(
            $usuario,
            $caja,
            (string) $request->input('monto_entregado'),
            $request->string('justificacion')->toString(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Caja cerrada.']);

        return to_route('finanzas.cajas.show', $caja);
    }
}
