<?php

namespace Patronato\Presentacion\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\ReporteRecaudacionService;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\FiltrarRecaudacionRequest;

class ReporteRecaudacionController extends Controller
{
    public function __construct(private ReporteRecaudacionService $reportes) {}

    /**
     * Muestra la recaudación por período y forma de pago con los filtros elegidos
     */
    public function index(FiltrarRecaudacionRequest $request): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $filtros = $request->filtros($this->reportes->fechasPredeterminadas());
        $reporte = $this->reportes->generar((int) $usuario->residencial_id, $filtros);

        return Inertia::render('reportes/recaudacion', [
            'cobradores' => $this->reportes->cobradores(),
            'filtros' => [
                'cobrador' => $filtros['cobrador_id'],
                'desde' => $filtros['desde'],
                'hasta' => $filtros['hasta'],
                'agrupar' => $filtros['agrupacion'],
            ],
            'reporte' => $reporte->paraPantalla(),
        ]);
    }
}
