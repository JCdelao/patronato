<?php

namespace Patronato\Presentacion\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\ReporteMorosidadService;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\OrdenarMorosidadRequest;
use Symfony\Component\HttpFoundation\Response as RespuestaHttp;

class ReporteMorosidadController extends Controller
{
    public function __construct(private ReporteMorosidadService $reportes) {}

    /**
     * Muestra las viviendas con saldo pendiente en el orden elegido
     */
    public function index(OrdenarMorosidadRequest $request): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('reportes/morosidad', [
            'reporte' => $this->reportes->generar((int) $usuario->residencial_id, $request->orden())->paraPantalla(),
        ]);
    }

    /**
     * Descarga el reporte en PDF o Excel con el mismo orden
     */
    public function exportar(OrdenarMorosidadRequest $request, string $formato): RespuestaHttp
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $archivo = $this->reportes->exportar($usuario, $request->orden(), $formato);

        return response($archivo['contenido'], 200, [
            'Content-Type' => $archivo['tipo'],
            'Content-Disposition' => 'attachment; filename="'.$archivo['nombre'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
