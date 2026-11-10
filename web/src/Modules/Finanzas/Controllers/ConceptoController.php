<?php

namespace Patronato\Modules\Finanzas\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Modules\Finanzas\Controllers\Requests\GuardarConceptoRequest;
use Patronato\Modules\Finanzas\Models\ConceptoCobro;
use Patronato\Modules\Finanzas\Models\Tarifa;
use Patronato\Modules\Finanzas\Services\ConceptoService;
use Patronato\Modules\Finanzas\Services\ModalidadService;
use Patronato\Modules\Finanzas\Services\TarifaService;

class ConceptoController extends Controller
{
    /**
     * Muestra el catálogo de conceptos de cobro
     */
    public function index(Request $request, TarifaService $tarifas, ModalidadService $modalidades): Response
    {
        $conceptos = ConceptoCobro::query()
            ->withExists('cargos as tiene_cargos')
            ->orderByDesc('activo')
            ->orderByDesc('es_cuota_unificada')
            ->orderBy('nombre')
            ->get();

        /** @var array<int, int> $ids */
        $ids = $conceptos->pluck('id')->all();
        $montos = $tarifas->montosGenerales($ids);
        $periodo = $modalidades->periodoActual()->toDateString();

        return Inertia::render('finanzas/conceptos', [
            'conceptos' => $conceptos->map(function (ConceptoCobro $concepto) use ($montos, $periodo) {
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
            'puedeEditar' => $request->user()->puede('parametros', 'registrar'),
        ]);
    }

    /**
     * Registra un concepto de cobro
     */
    public function store(GuardarConceptoRequest $request, ConceptoService $conceptos): RedirectResponse
    {
        /** @var array{nombre: string, tipo: string, monto: string, vigente_desde: string} $datos */
        $datos = $request->validated();

        $conceptos->registrar($request->user(), $datos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Concepto registrado.']);

        return back();
    }

    /**
     * Actualiza un concepto de cobro
     */
    public function update(GuardarConceptoRequest $request, ConceptoService $conceptos, ConceptoCobro $concepto): RedirectResponse
    {
        $this->editable($concepto);

        /** @var array{nombre: string, tipo: string, monto: string, vigente_desde: string} $datos */
        $datos = $request->validated();

        $conceptos->actualizar($request->user(), $concepto, $datos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Concepto actualizado.']);

        return back();
    }

    /**
     * Desactiva un concepto de cobro
     */
    public function desactivar(Request $request, ConceptoService $conceptos, ConceptoCobro $concepto): RedirectResponse
    {
        $this->editable($concepto);

        $conceptos->desactivar($request->user(), $concepto);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$concepto->nombre} quedó inactivo."]);

        return back();
    }

    /**
     * Reactiva un concepto de cobro
     */
    public function reactivar(Request $request, ConceptoService $conceptos, ConceptoCobro $concepto): RedirectResponse
    {
        abort_if($concepto->activo || $concepto->es_cuota_unificada, 404);

        $conceptos->reactivar($request->user(), $concepto);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$concepto->nombre} reactivado."]);

        return back();
    }

    /**
     * Elimina un concepto de cobro sin cargos
     */
    public function destroy(Request $request, ConceptoService $conceptos, ConceptoCobro $concepto): RedirectResponse
    {
        $this->editable($concepto);

        try {
            $conceptos->eliminar($request->user(), $concepto);
        } catch (ValidationException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Concepto eliminado.']);

        return back();
    }

    /**
     * La cuota mensual se define en el padrón y un concepto inactivo no se edita
     */
    private function editable(ConceptoCobro $concepto): void
    {
        abort_if(! $concepto->activo || $concepto->es_cuota_unificada, 404);
    }
}
