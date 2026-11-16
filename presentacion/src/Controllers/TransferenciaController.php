<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Negocio\Services\TransferenciaService;
use Patronato\Negocio\Services\ViviendaService;
use Patronato\Persistencia\Models\Pago;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\RegistrarTransferenciaRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransferenciaController extends Controller
{
    public function __construct(private TransferenciaService $transferencias) {}

    /**
     * Muestra el registro de una transferencia
     */
    public function create(Request $request, ViviendaService $viviendas): Response
    {
        $buscar = $request->string('buscar')->trim()->toString();
        $viviendaId = $request->integer('vivienda') ?: null;

        return Inertia::render('finanzas/transferencia', [
            'buscar' => $buscar,
            'viviendas' => $buscar === '' ? [] : $viviendas->opcionesActivas($buscar),
            'vivienda' => $viviendaId === null ? null : $viviendas->opcionesActivas(viviendaId: $viviendaId)[0] ?? null,
            'hoy' => TransferenciaService::hoy(),
            'tamanoMaximoMb' => TransferenciaService::TAMANO_MAXIMO_KB / 1024,
        ]);
    }

    /**
     * Registra la transferencia, que queda pendiente de verificación
     */
    public function store(RegistrarTransferenciaRequest $request, PermisoService $permisos): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        /** @var array{vivienda_id: int|string, monto: string, fecha_pago: string, banco: string, numero_referencia: string, cuenta_receptora: string} $datos */
        $datos = $request->safe()->except('comprobante');

        /** @var UploadedFile $comprobante */
        $comprobante = $request->file('comprobante');

        $pago = $this->transferencias->registrar($usuario, $datos, $comprobante);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transferencia registrada. Queda pendiente de verificación.']);

        // Quien no ve los cobros vuelve al formulario para registrar otra
        return $permisos->puede($usuario, 'cobros', 'ver')
            ? to_route('finanzas.pagos.show', $pago)
            : to_route('finanzas.transferencias.create');
    }

    /**
     * Muestra el comprobante adjunto a la transferencia
     */
    public function comprobante(Pago $pago): StreamedResponse
    {
        $ruta = $this->transferencias->comprobante($pago);

        abort_if($ruta === null, 404);

        return Storage::disk(TransferenciaService::DISCO)->response($ruta, "comprobante-transferencia-{$pago->id}.".pathinfo($ruta, PATHINFO_EXTENSION));
    }
}
