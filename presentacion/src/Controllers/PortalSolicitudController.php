<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\SolicitudCorreccionService;
use Patronato\Persistencia\Models\SolicitudCorreccion;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\RegistrarSolicitudCorreccionRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Solicitudes de corrección que el residente envía y sigue desde el portal (HU-54)
 */
class PortalSolicitudController extends Controller
{
    public function __construct(private SolicitudCorreccionService $solicitudes) {}

    /**
     * Formulario y solicitudes del residente; el tipo y la vivienda pueden venir ya elegidos
     */
    public function index(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $tipo = $request->string('tipo')->toString();

        return Inertia::render('portal/solicitudes', [
            'solicitudes' => $this->solicitudes->deUsuario($usuario),
            'viviendas' => $this->solicitudes->viviendasDe($usuario),
            'tipos' => SolicitudCorreccion::TIPOS,
            'inicial' => [
                'tipo' => array_key_exists($tipo, SolicitudCorreccion::TIPOS) ? $tipo : null,
                'vivienda_id' => $request->integer('vivienda') ?: null,
            ],
            'tamanoMaximoMb' => intdiv(SolicitudCorreccionService::TAMANO_MAXIMO_KB, 1024),
        ]);
    }

    public function store(RegistrarSolicitudCorreccionRequest $request): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        /** @var array{tipo: string, vivienda_id: int|string, descripcion: string} $datos */
        $datos = $request->safe()->only(['tipo', 'vivienda_id', 'descripcion']);

        $adjunto = $request->file('adjunto');

        $this->solicitudes->crear($usuario, $datos, $adjunto instanceof UploadedFile ? $adjunto : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Solicitud enviada. La Junta Directiva la revisará.']);

        return to_route('portal.solicitudes.index');
    }

    /**
     * Abre el archivo adjunto de una solicitud propia
     */
    public function adjunto(Request $request, SolicitudCorreccion $solicitud): StreamedResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $ruta = $this->solicitudes->adjuntoDeResidente($usuario, $solicitud);

        abort_if($ruta === null, 404);

        return Storage::disk(SolicitudCorreccionService::DISCO)->response($ruta, "solicitud-{$solicitud->id}.".pathinfo($ruta, PATHINFO_EXTENSION));
    }
}
