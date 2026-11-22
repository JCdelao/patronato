<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\TransferenciaService;
use Patronato\Negocio\Services\VerificacionTransferenciaService;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\ConfirmarTransferenciaRequest;
use Patronato\Presentacion\Requests\RechazarTransferenciaRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bandeja y verificación de transferencias (HU-26)
 *
 * Se abre con transferencias.verificar, sin cobros.ver: el presidente puede
 * verificar aunque no vea la lista de cobros. Solo abre transferencias.
 */
class VerificacionTransferenciaController extends Controller
{
    public function __construct(private VerificacionTransferenciaService $verificacion) {}

    /**
     * Transferencias pendientes con los días que llevan sin verificar
     */
    public function index(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('finanzas/transferencias-por-verificar', [
            'transferencias' => $this->verificacion->pendientes($usuario),
        ]);
    }

    /**
     * Muestra la transferencia para confirmarla o rechazarla
     */
    public function show(Request $request, Pago $pago): Response
    {
        abort_unless($pago->forma_pago === Pago::TRANSFERENCIA, 404);

        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('finanzas/verificar-transferencia', [
            'transferencia' => $this->verificacion->detalle($usuario, $pago),
        ]);
    }

    /**
     * Confirma la transferencia y la aplica a los meses elegidos
     */
    public function confirmar(ConfirmarTransferenciaRequest $request, Pago $pago): RedirectResponse
    {
        abort_unless($pago->forma_pago === Pago::TRANSFERENCIA, 404);

        /** @var User $usuario */
        $usuario = $request->user();

        /** @var array<int, int|string> $cargos */
        $cargos = $request->input('cargos') ?? [];

        $pago = $this->verificacion->confirmar($usuario, $pago, array_map(intval(...), $cargos));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Transferencia confirmada. Recibo N.º {$pago->numero_recibo}."]);

        return to_route('finanzas.transferencias.verificar.index');
    }

    /**
     * Rechaza la transferencia con su motivo
     */
    public function rechazar(RechazarTransferenciaRequest $request, Pago $pago): RedirectResponse
    {
        abort_unless($pago->forma_pago === Pago::TRANSFERENCIA, 404);

        /** @var User $usuario */
        $usuario = $request->user();

        $this->verificacion->rechazar($usuario, $pago, (string) $request->input('motivo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transferencia rechazada. La referencia queda libre.']);

        return to_route('finanzas.transferencias.verificar.index');
    }

    /**
     * Muestra el comprobante adjunto a la transferencia
     */
    public function comprobante(Pago $pago, TransferenciaService $transferencias): StreamedResponse
    {
        $ruta = $transferencias->comprobante($pago);

        abort_if($ruta === null, 404);

        return Storage::disk(TransferenciaService::DISCO)->response($ruta, "comprobante-transferencia-{$pago->id}.".pathinfo($ruta, PATHINFO_EXTENSION));
    }
}
