<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\AnulacionPagoService;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\SolicitarAnulacionRequest;

class PagoController extends Controller
{
    public function __construct(private AnulacionPagoService $anulaciones) {}

    /**
     * Lista los pagos del residencial con su estado
     */
    public function index(Request $request): Response
    {
        $buscar = $request->string('buscar')->trim()->toString();

        return Inertia::render('finanzas/pagos', [
            'buscar' => $buscar,
            'pagos' => $this->anulaciones->listado($buscar),
        ]);
    }

    /**
     * Muestra el pago, su anulación y sus solicitudes
     */
    public function show(Request $request, Pago $pago): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('finanzas/pago', [
            'pago' => $this->anulaciones->detalle($usuario, $pago),
        ]);
    }

    /**
     * Solicita la anulación del pago; otro directivo debe aprobarla
     */
    public function solicitarAnulacion(SolicitarAnulacionRequest $request, Pago $pago): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $this->anulaciones->solicitar($usuario, $pago, (string) $request->input('motivo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Solicitud enviada. Otro directivo debe aprobar la anulación.']);

        return to_route('finanzas.pagos.show', $pago);
    }
}
