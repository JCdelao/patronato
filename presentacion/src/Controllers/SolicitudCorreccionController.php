<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\SolicitudCorreccionService;
use Patronato\Persistencia\Models\SolicitudCorreccion;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\CerrarSolicitudCorreccionRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bandeja donde la Junta Directiva atiende las solicitudes de corrección (HU-54)
 *
 * La bandeja no cambia datos: la corrección se hace en el módulo que corresponde.
 */
class SolicitudCorreccionController extends Controller
{
    public function __construct(private SolicitudCorreccionService $solicitudes) {}

    /**
     * Solicitudes abiertas (predeterminado) o cerradas
     */
    public function index(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $vista = $request->string('vista')->toString() === 'cerradas' ? 'cerradas' : 'abiertas';

        return Inertia::render('solicitudes/index', [
            'solicitudes' => $this->solicitudes->bandeja($usuario, $vista === 'abiertas'),
            'vista' => $vista,
            'tipos' => SolicitudCorreccion::TIPOS,
        ]);
    }

    /**
     * Toma la solicitud en revisión
     */
    public function revisar(Request $request, SolicitudCorreccion $solicitud): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $this->solicitudes->tomar($usuario, $solicitud);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Solicitud en revisión.']);

        return back();
    }

    public function resolver(CerrarSolicitudCorreccionRequest $request, SolicitudCorreccion $solicitud): RedirectResponse
    {
        return $this->cerrar($request, $solicitud, true);
    }

    public function rechazar(CerrarSolicitudCorreccionRequest $request, SolicitudCorreccion $solicitud): RedirectResponse
    {
        return $this->cerrar($request, $solicitud, false);
    }

    /**
     * Abre el archivo adjunto de la solicitud
     */
    public function adjunto(SolicitudCorreccion $solicitud): StreamedResponse
    {
        $ruta = $this->solicitudes->adjunto($solicitud);

        abort_if($ruta === null, 404);

        return Storage::disk(SolicitudCorreccionService::DISCO)->response($ruta, "solicitud-{$solicitud->id}.".pathinfo($ruta, PATHINFO_EXTENSION));
    }

    /**
     * Cierra la solicitud con la respuesta para el residente
     */
    private function cerrar(CerrarSolicitudCorreccionRequest $request, SolicitudCorreccion $solicitud, bool $resuelta): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $this->solicitudes->cerrar($usuario, $solicitud, $resuelta, (string) $request->input('respuesta'));

        Inertia::flash('toast', ['type' => 'success', 'message' => $resuelta ? 'Solicitud resuelta. Se avisó al residente.' : 'Solicitud rechazada. Se avisó al residente.']);

        return back();
    }
}
