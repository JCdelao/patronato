<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\ComprobanteService;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\EnviarComprobanteRequest;
use Symfony\Component\HttpFoundation\Response as RespuestaHttp;

class ComprobanteController extends Controller
{
    public function __construct(private ComprobanteService $comprobantes) {}

    /**
     * Muestra el comprobante con las opciones para descargarlo y compartirlo
     */
    public function show(Request $request, Pago $pago, PermisoService $permisos): Response
    {
        $this->autorizar($request, $pago);

        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('finanzas/comprobante', [
            'puedeCobrar' => $permisos->puede($usuario, 'cobros', 'registrar'),
            'comprobante' => $this->comprobantes->datos($pago),
            'correos' => $this->comprobantes->correosSugeridos($pago),
            'enlace' => URL::temporarySignedRoute(
                'comprobantes.publico',
                now()->addDays(ComprobanteService::DIAS_ENLACE),
                ['pago' => $pago->id],
            ),
            'diasEnlace' => ComprobanteService::DIAS_ENLACE,
        ]);
    }

    /**
     * Descarga el comprobante en PDF
     */
    public function pdf(Request $request, Pago $pago): RespuestaHttp
    {
        $this->autorizar($request, $pago);

        return $this->respuestaPdf($pago, 'attachment');
    }

    /**
     * Envía el comprobante por correo
     */
    public function enviar(EnviarComprobanteRequest $request, Pago $pago): RedirectResponse
    {
        $this->autorizar($request, $pago);

        /** @var User $usuario */
        $usuario = $request->user();
        $correos = $request->correos();

        $this->comprobantes->enviarPorCorreo($usuario, $pago, $correos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Comprobante enviado a '.implode(', ', $correos).'.']);

        return back();
    }

    /**
     * Abre el comprobante desde el enlace firmado, sin iniciar sesión
     */
    public function publico(int $pago): RespuestaHttp
    {
        $registro = $this->comprobantes->delEnlace($pago);

        abort_if($registro === null, 404);

        return $this->respuestaPdf($registro, 'inline');
    }

    /**
     * Deniega con 403 a quien no puede ver el comprobante
     */
    private function autorizar(Request $request, Pago $pago): void
    {
        /** @var User $usuario */
        $usuario = $request->user();

        abort_unless($this->comprobantes->puedeVer($usuario, $pago), 403, 'Acceso denegado');
        abort_unless($this->comprobantes->tieneComprobante($pago), 404);
    }

    private function respuestaPdf(Pago $pago, string $disposicion): RespuestaHttp
    {
        return response($this->comprobantes->pdf($pago), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposicion.'; filename="'.$this->comprobantes->nombreArchivo($pago).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
