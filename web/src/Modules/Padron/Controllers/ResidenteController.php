<?php

namespace Patronato\Modules\Padron\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Patronato\Modules\Padron\Controllers\Requests\GuardarResidenteRequest;
use Patronato\Modules\Padron\Models\Residente;
use Patronato\Modules\Padron\Services\ResidenteService;

class ResidenteController extends Controller
{
    /**
     * Registra un residente en una vivienda.
     */
    public function store(GuardarResidenteRequest $request, ResidenteService $residentes): RedirectResponse
    {
        /** @var array{vivienda_id: string, tipo: string, nombres: string, apellidos: string, identidad?: string|null, telefono?: string|null, correo?: string|null} $datos */
        $datos = $request->safe()->except('es_responsable_pago');

        $residentes->registrar((int) $request->user()->residencial_id, $datos, $request->boolean('es_responsable_pago'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Residente registrado.']);

        return back();
    }

    /**
     * Actualiza un residente del residencial.
     */
    public function update(GuardarResidenteRequest $request, ResidenteService $residentes, Residente $residente): RedirectResponse
    {
        abort_unless($residente->activo, 404);

        /** @var array{vivienda_id: string, tipo: string, nombres: string, apellidos: string, identidad?: string|null, telefono?: string|null, correo?: string|null} $datos */
        $datos = $request->safe()->except('es_responsable_pago');

        $residentes->actualizar($residente, $datos, $request->boolean('es_responsable_pago'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Residente actualizado.']);

        return back();
    }

    /**
     * Da de baja a un residente; su historial se conserva.
     */
    public function destroy(ResidenteService $residentes, Residente $residente): RedirectResponse
    {
        abort_unless($residente->activo, 404);

        $residentes->darDeBaja($residente);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Residente dado de baja.']);

        return back();
    }
}
