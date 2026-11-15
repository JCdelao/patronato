<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\AnulacionPagoService;
use Patronato\Persistencia\Models\SolicitudAnulacion;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\RechazarAnulacionRequest;

class AnulacionController extends Controller
{
    public function __construct(private AnulacionPagoService $anulaciones) {}

    /**
     * Lista las solicitudes de anulación por aprobar
     */
    public function index(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('finanzas/anulaciones', [
            'solicitudes' => $this->anulaciones->pendientes($usuario),
        ]);
    }

    /**
     * Aprueba la solicitud y anula el pago
     */
    public function aprobar(Request $request, SolicitudAnulacion $solicitud): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $pago = $this->anulaciones->aprobar($usuario, $solicitud);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pago con recibo N.º {$pago->numero_recibo} anulado."]);

        return to_route('finanzas.anulaciones.index');
    }

    /**
     * Rechaza la solicitud; el pago sigue confirmado
     */
    public function rechazar(RechazarAnulacionRequest $request, SolicitudAnulacion $solicitud): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $this->anulaciones->rechazar($usuario, $solicitud, (string) $request->input('motivo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Solicitud rechazada. El pago sigue confirmado.']);

        return to_route('finanzas.anulaciones.index');
    }
}
