<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Negocio\Services\TransferenciaService;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Transferencia reportada por un vecino, para quien recibe el aviso (HU-25)
 *
 * Se abre con transferencias.registrar, sin cobros.ver: no muestra el saldo
 * de la vivienda. Solo abre los reportes del portal; las transferencias que
 * registró el tesorero (HU-24) responden 404.
 */
class TransferenciaReportadaController extends Controller
{
    public function __construct(private TransferenciaService $transferencias) {}

    /**
     * Muestra el reporte enlazado en el correo
     */
    public function show(Request $request, Pago $pago, PermisoService $permisos): Response
    {
        abort_unless($this->transferencias->esReportePortal($pago), 404);

        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('finanzas/transferencia-reportada', [
            'reporte' => $this->transferencias->detalleReporte($pago),
            'puedeVerCobros' => $permisos->puede($usuario, 'cobros', 'ver'),
        ]);
    }

    /**
     * Muestra el comprobante adjunto al reporte
     */
    public function comprobante(Pago $pago): StreamedResponse
    {
        abort_unless($this->transferencias->esReportePortal($pago), 404);

        $ruta = $this->transferencias->comprobante($pago);

        abort_if($ruta === null, 404);

        return Storage::disk(TransferenciaService::DISCO)->response($ruta, "comprobante-transferencia-{$pago->id}.".pathinfo($ruta, PATHINFO_EXTENSION));
    }
}
