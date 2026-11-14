<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\ConceptoService;
use Patronato\Negocio\Services\PeriodoService;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Negocio\Services\TarifaEspecialService;
use Patronato\Negocio\Services\TarifaService;
use Patronato\Negocio\Services\TipoViviendaService;
use Patronato\Negocio\Services\ViviendaService;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Persistencia\Models\Tarifa;
use Patronato\Persistencia\Models\TipoVivienda;

class ParametroCobroController extends Controller
{
    /**
     * Muestra los tipos de vivienda con su cuota y los conceptos de cobro con sus montos
     */
    public function index(
        Request $request,
        TipoViviendaService $tiposVivienda,
        TarifaEspecialService $tarifasEspeciales,
        ViviendaService $viviendas,
        ConceptoService $conceptos,
        TarifaService $tarifas,
        PeriodoService $periodos,
        PermisoService $permisos,
    ): Response {
        $periodo = $periodos->actual()->toDateString();

        $tipos = $tiposVivienda->catalogo();

        /** @var array<int, int> $tipoIds */
        $tipoIds = $tipos->pluck('id')->all();
        $cuotas = $tiposVivienda->cuotas((int) $request->user()->residencial_id, $tipoIds);

        $especiales = [];

        foreach ($tarifasEspeciales->porVivienda((int) $request->user()->residencial_id) as $viviendaId => $tarifasDeVivienda) {
            $vigencias = $this->vigencias($tarifasDeVivienda, $periodo);

            // Las cuotas especiales que ya terminaron no se muestran
            if ($vigencias['actual'] !== null || $vigencias['proximos'] !== []) {
                $especiales[] = [
                    'vivienda_id' => $viviendaId,
                    'codigo' => $tarifasDeVivienda[0]->vivienda?->codigo,
                    ...$vigencias,
                ];
            }
        }

        $catalogo = $conceptos->catalogo();

        /** @var array<int, int> $conceptoIds */
        $conceptoIds = $catalogo->pluck('id')->all();
        $montos = $tarifas->montosGenerales($conceptoIds);

        return Inertia::render('finanzas/parametros', [
            'tipos' => $tipos->map(fn (TipoVivienda $tipo) => [
                'id' => $tipo->id,
                'nombre' => $tipo->nombre,
                'activo' => $tipo->activo,
                'tiene_viviendas' => (bool) $tipo->getAttribute('tiene_viviendas'),
                ...$this->vigencias($cuotas[$tipo->id] ?? [], $periodo),
            ]),
            'conceptos' => $catalogo->map(fn (ConceptoCobro $concepto) => [
                'id' => $concepto->id,
                'nombre' => $concepto->nombre,
                'tipo' => $concepto->tipo,
                'activo' => $concepto->activo,
                'es_cuota_unificada' => $concepto->es_cuota_unificada,
                'tiene_cargos' => (bool) $concepto->getAttribute('tiene_cargos'),
                ...$this->vigencias($montos[$concepto->id] ?? [], $periodo),
            ]),
            'especiales' => collect($especiales)->sortBy('codigo')->values(),
            'viviendas' => $viviendas->activas(),
            'periodoActual' => $periodo,
            'puedeEditar' => $permisos->puede($request->user(), 'parametros', 'registrar'),
        ]);
    }

    /**
     * Separa el monto que rige en el período actual de los que empiezan después
     *
     * @param  list<Tarifa>  $tarifas
     * @return array{actual: array{monto: string, desde: string, motivo: string|null}|null, proximos: list<array{monto: string, desde: string, motivo: string|null}>}
     */
    private function vigencias(array $tarifas, string $periodo): array
    {
        $vigentes = collect($tarifas)->filter(fn (Tarifa $tarifa) => $tarifa->vigente_desde->toDateString() <= $periodo
            // Un monto que ya terminó no es el actual
            && ($tarifa->vigente_hasta === null || $tarifa->vigente_hasta->toDateString() >= $periodo));

        $proximos = collect($tarifas)->filter(fn (Tarifa $tarifa) => $tarifa->vigente_desde->toDateString() > $periodo);

        $datos = fn (Tarifa $tarifa) => [
            'monto' => $tarifa->monto,
            'desde' => $tarifa->vigente_desde->toDateString(),
            'motivo' => $tarifa->motivo,
        ];

        $actual = $vigentes->last();

        return [
            'actual' => $actual === null ? null : $datos($actual),
            'proximos' => array_values($proximos->map($datos)->all()),
        ];
    }
}
