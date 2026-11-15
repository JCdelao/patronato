<?php

namespace Patronato\Presentacion\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\ReporteCobrosService;
use Patronato\Presentacion\Requests\FiltrarReporteCobrosRequest;
use Symfony\Component\HttpFoundation\Response as RespuestaHttp;

class ReporteCobrosController extends Controller
{
    public function __construct(private ReporteCobrosService $reportes) {}

    /**
     * Muestra el resumen de cobros por cobrador con los filtros elegidos
     */
    public function index(FiltrarReporteCobrosRequest $request): Response
    {
        $filtros = $request->filtros($this->reportes->fechasPredeterminadas());

        return Inertia::render('reportes/cobros', [
            'cobradores' => $this->reportes->cobradores(),
            'filtros' => [
                'cobrador' => $filtros['cobrador_id'],
                'desde' => $filtros['desde'],
                'hasta' => $filtros['hasta'],
                'estado' => $filtros['estado_caja'] ?? 'todas',
                'agrupar' => $filtros['agrupacion'],
            ],
            'reporte' => $this->reportes->generar($filtros)->resumenParaPantalla(),
        ]);
    }

    /**
     * Descarga el reporte en PDF o Excel con los mismos filtros
     */
    public function exportar(FiltrarReporteCobrosRequest $request, string $formato): RespuestaHttp
    {
        $archivo = $this->reportes->exportar($request->filtros($this->reportes->fechasPredeterminadas()), $formato);

        return response($archivo['contenido'], 200, [
            'Content-Type' => $archivo['tipo'],
            'Content-Disposition' => 'attachment; filename="'.$archivo['nombre'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
