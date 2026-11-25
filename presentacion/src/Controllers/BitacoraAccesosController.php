<?php

namespace Patronato\Presentacion\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\BitacoraAccesosService;
use Patronato\Presentacion\Requests\FiltrarBitacoraAccesosRequest;
use Symfony\Component\HttpFoundation\Response as RespuestaHttp;

class BitacoraAccesosController extends Controller
{
    public function __construct(private BitacoraAccesosService $bitacora) {}

    /**
     * Muestra las entradas, salidas e incidencias con los filtros elegidos
     */
    public function index(FiltrarBitacoraAccesosRequest $request): Response
    {
        $residencialId = (int) $request->user()->residencial_id;
        $filtros = $request->filtros($this->bitacora->fechasPredeterminadas());

        return Inertia::render('vigilancia/bitacora-accesos', [
            'filtros' => [
                'desde' => $filtros['desde'],
                'hasta' => $filtros['hasta'],
                'tipo' => $filtros['tipo'],
                'turno' => $filtros['turno'],
                'guardia' => $filtros['guardia_id'],
                'vivienda' => $filtros['vivienda_id'],
                'medio' => $filtros['medio_entrada_id'],
            ],
            'opciones' => $this->bitacora->opciones($residencialId),
            ...$this->bitacora->listado($residencialId, $filtros),
            'maximoExportacion' => BitacoraAccesosService::MAXIMO_EXPORTACION,
        ]);
    }

    /**
     * Descarga la bitácora en PDF o Excel con los mismos filtros
     */
    public function exportar(FiltrarBitacoraAccesosRequest $request, string $formato): RespuestaHttp
    {
        $archivo = $this->bitacora->exportar(
            (int) $request->user()->residencial_id,
            $request->filtros($this->bitacora->fechasPredeterminadas()),
            $formato,
        );

        return response($archivo['contenido'], 200, [
            'Content-Type' => $archivo['tipo'],
            'Content-Disposition' => 'attachment; filename="'.$archivo['nombre'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
