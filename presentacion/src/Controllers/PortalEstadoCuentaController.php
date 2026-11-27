<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\PortalEstadoCuentaService;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Presentacion\Requests\GenerarEstadoCuentaPdfRequest;
use Symfony\Component\HttpFoundation\Response as RespuestaHttp;

/**
 * Estado de cuenta que el residente consulta desde el portal
 */
class PortalEstadoCuentaController extends Controller
{
    public function __construct(private PortalEstadoCuentaService $estados) {}

    /**
     * Abre la vivienda del residente o le deja elegir si vive en varias
     */
    public function index(Request $request): Response|RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $viviendas = $this->estados->viviendasDe($usuario);

        if (count($viviendas) === 1) {
            return to_route('portal.estado-cuenta.show', $viviendas[0]['id']);
        }

        return Inertia::render('portal/estado-cuenta', [
            'viviendas' => $viviendas,
            'estado' => null,
            'rango' => $this->estados->rangoPredeterminado(),
        ]);
    }

    /**
     * Muestra el estado de cuenta de una vivienda propia, completo o de un año
     */
    public function show(Request $request, Vivienda $vivienda): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('portal/estado-cuenta', [
            'viviendas' => $this->estados->viviendasDe($usuario),
            'estado' => $this->estados->estadoDe($usuario, $vivienda, $request->integer('anio') ?: null),
            'rango' => $this->estados->rangoPredeterminado(),
        ]);
    }

    /**
     * Descarga el estado de cuenta en PDF de una vivienda propia
     */
    public function pdf(GenerarEstadoCuentaPdfRequest $request, Vivienda $vivienda): RespuestaHttp
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $archivo = $this->estados->pdf($usuario, $vivienda, (string) $request->input('desde'), (string) $request->input('hasta'));

        return response($archivo['contenido'], 200, [
            'Content-Type' => $archivo['tipo'],
            'Content-Disposition' => 'attachment; filename="'.$archivo['nombre'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
