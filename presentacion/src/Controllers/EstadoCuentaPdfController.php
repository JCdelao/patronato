<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\EstadoCuentaPdfService;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Presentacion\Requests\GenerarEstadoCuentaPdfRequest;
use Symfony\Component\HttpFoundation\Response as RespuestaHttp;

class EstadoCuentaPdfController extends Controller
{
    public function __construct(private EstadoCuentaPdfService $pdf) {}

    /**
     * Busca la vivienda y muestra el formulario con el rango de fechas
     */
    public function index(Request $request): Response
    {
        $buscar = $request->string('buscar')->trim()->toString();
        $viviendaId = $request->integer('vivienda') ?: null;

        return Inertia::render('finanzas/estado-cuenta-pdf', [
            'buscar' => $buscar,
            'viviendas' => $this->pdf->buscar($buscar),
            'vivienda' => $viviendaId === null ? null : $this->pdf->opcion($viviendaId),
            'rango' => $this->pdf->rangoPredeterminado(),
        ]);
    }

    /**
     * Descarga el estado de cuenta en PDF del rango elegido
     *
     * Una vivienda de otro residencial no se encuentra por el filtro del modelo.
     */
    public function descargar(GenerarEstadoCuentaPdfRequest $request, Vivienda $vivienda): RespuestaHttp
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $archivo = $this->pdf->generar($usuario, $vivienda, (string) $request->input('desde'), (string) $request->input('hasta'));

        return response($archivo['contenido'], 200, [
            'Content-Type' => $archivo['tipo'],
            'Content-Disposition' => 'attachment; filename="'.$archivo['nombre'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
