<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\ConceptoService;
use Patronato\Negocio\Services\PeriodoService;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Negocio\Services\TarifaService;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Persistencia\Models\Tarifa;

class ParametroCobroController extends Controller
{
    /**
     * Muestra los conceptos de cobro con sus montos
     */
    public function index(
        Request $request,
        ConceptoService $conceptos,
        TarifaService $tarifas,
        PeriodoService $periodos,
        PermisoService $permisos,
    ): Response {
        $periodo = $periodos->actual()->toDateString();

        $catalogo = $conceptos->catalogo();

        /** @var array<int, int> $ids */
        $ids = $catalogo->pluck('id')->all();
        $montos = $tarifas->montosGenerales($ids);

        return Inertia::render('finanzas/parametros', [
            'conceptos' => $catalogo->map(function (ConceptoCobro $concepto) use ($montos, $periodo) {
                $vigencias = collect($montos[$concepto->id] ?? [])->map(fn (Tarifa $tarifa) => [
                    'monto' => $tarifa->monto,
                    'desde' => $tarifa->vigente_desde->toDateString(),
                ]);

                return [
                    'id' => $concepto->id,
                    'nombre' => $concepto->nombre,
                    'tipo' => $concepto->tipo,
                    'activo' => $concepto->activo,
                    'es_cuota_unificada' => $concepto->es_cuota_unificada,
                    'tiene_cargos' => (bool) $concepto->getAttribute('tiene_cargos'),
                    'actual' => $vigencias->filter(fn (array $vigencia) => $vigencia['desde'] <= $periodo)->last(),
                    'proximos' => $vigencias->filter(fn (array $vigencia) => $vigencia['desde'] > $periodo)->values(),
                ];
            }),
            'periodoActual' => $periodo,
            'puedeEditar' => $permisos->puede($request->user(), 'parametros', 'registrar'),
        ]);
    }
}
