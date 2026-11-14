<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Patronato\Negocio\Services\ConceptoService;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Presentacion\Requests\GuardarConceptoRequest;

class ConceptoController extends Controller
{
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
     * La cuota mensual de cada vivienda y un concepto inactivo no se editan aquí
     */
    private function editable(ConceptoCobro $concepto): void
    {
        abort_if(! $concepto->activo || $concepto->es_cuota_unificada, 404);
    }
}
