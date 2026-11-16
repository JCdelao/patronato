<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\TransferenciaService;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\ReportarTransferenciaRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Transferencias que el residente reporta desde el portal (HU-25)
 */
class PortalTransferenciaController extends Controller
{
    public function __construct(private TransferenciaService $transferencias) {}

    /**
     * Muestra los reportes del residente con su estado
     */
    public function index(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('portal/transferencias', [
            'reportes' => $this->transferencias->misReportes($usuario),
        ]);
    }

    /**
     * Muestra el formulario para reportar una transferencia
     */
    public function create(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('portal/reportar-transferencia', [
            'viviendas' => $this->transferencias->viviendasDe($usuario),
            'hoy' => TransferenciaService::hoy(),
            'tamanoMaximoMb' => TransferenciaService::TAMANO_MAXIMO_KB / 1024,
        ]);
    }

    /**
     * Guarda el reporte, que queda pendiente hasta que tesorería lo verifique
     */
    public function store(ReportarTransferenciaRequest $request): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        /** @var array{vivienda_id: int|string, monto: string, fecha_pago: string, banco: string, numero_referencia: string, cuenta_receptora?: string|null} $datos */
        $datos = $request->safe()->except('comprobante');

        /** @var UploadedFile $comprobante */
        $comprobante = $request->file('comprobante');

        $this->transferencias->reportar($usuario, $datos, $comprobante);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transferencia reportada. Tesorería la verificará.']);

        return to_route('portal.transferencias.index');
    }

    /**
     * Muestra el comprobante de un reporte propio
     */
    public function comprobante(Request $request, Pago $pago): StreamedResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $this->transferencias->residenteDe($usuario);

        // Un residente solo abre los comprobantes que él mismo reportó
        abort_unless($this->transferencias->esReporteDe($usuario, $pago), 403);

        $ruta = $this->transferencias->comprobante($pago);

        abort_if($ruta === null, 404);

        return Storage::disk(TransferenciaService::DISCO)->response($ruta, "comprobante-transferencia-{$pago->id}.".pathinfo($ruta, PATHINFO_EXTENSION));
    }
}
