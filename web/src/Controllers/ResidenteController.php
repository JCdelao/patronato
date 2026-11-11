<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\ResidenteService;
use Patronato\Negocio\Services\ViviendaService;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\ViviendaResidente;
use Patronato\Presentacion\Requests\AgregarViviendaRequest;
use Patronato\Presentacion\Requests\GuardarResidenteRequest;

class ResidenteController extends Controller
{
    /**
     * Muestra los residentes inactivos con las viviendas donde vivieron
     */
    public function index(Request $request, ResidenteService $servicio, ViviendaService $viviendas): Response
    {
        $buscar = $request->string('buscar')->trim()->toString();

        $residentes = $servicio->inactivos($buscar);

        return Inertia::render('padron/residentes', [
            'residentes' => $residentes->through(fn (Residente $residente) => [
                'id' => $residente->id,
                'nombres' => $residente->nombres,
                'apellidos' => $residente->apellidos,
                'identidad' => $residente->identidad,
                'telefono' => $residente->telefono,
                'correo' => $residente->correo,
                'historial' => $residente->vinculos->map(fn (ViviendaResidente $vinculo) => [
                    'vivienda' => $vinculo->vivienda->codigo,
                    'tipo' => $vinculo->tipo,
                    'desde' => $vinculo->fecha_inicio?->toDateString(),
                    'hasta' => $vinculo->fecha_fin?->toDateString(),
                ]),
            ]),
            'opcionesViviendas' => $viviendas->activas(),
            'buscar' => $buscar,
        ]);
    }

    /**
     * Registra un residente en una vivienda
     */
    public function store(GuardarResidenteRequest $request, ResidenteService $residentes): RedirectResponse
    {
        /** @var array{vivienda_id: string, tipo: string, nombres: string, apellidos: string, identidad?: string|null, telefono?: string|null, correo?: string|null} $datos */
        $datos = $request->safe()->except(['es_responsable_pago', 'vivienda_origen_id']);

        $residentes->registrar($request->user(), $datos, $request->boolean('es_responsable_pago'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Residente registrado.']);

        return back();
    }

    /**
     * Actualiza un residente del residencial
     */
    public function update(GuardarResidenteRequest $request, ResidenteService $residentes, Residente $residente): RedirectResponse
    {
        abort_unless($residente->activo, 404);

        /** @var array{vivienda_id: string, tipo: string, nombres: string, apellidos: string, identidad?: string|null, telefono?: string|null, correo?: string|null} $datos */
        $datos = $request->safe()->except(['es_responsable_pago', 'vivienda_origen_id']);

        $residentes->actualizar(
            $request->user(),
            $residente,
            $request->filled('vivienda_origen_id') ? $request->integer('vivienda_origen_id') : null,
            $datos,
            $request->boolean('es_responsable_pago'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Residente actualizado.']);

        return back();
    }

    /**
     * Agrega otra vivienda a un residente o lo reactiva
     */
    public function agregarVivienda(AgregarViviendaRequest $request, ResidenteService $residentes, Residente $residente): RedirectResponse
    {
        $reactivado = ! $residente->activo;

        /** @var array{vivienda_id: string, tipo: string} $datos */
        $datos = $request->safe()->except('es_responsable_pago');

        $residentes->agregarVivienda($request->user(), $residente, $datos, $request->boolean('es_responsable_pago'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $reactivado ? "{$residente->nombres} fue reactivado." : 'Vivienda agregada al residente.',
        ]);

        return back();
    }

    /**
     * Saca a un residente de una vivienda o lo da de baja
     */
    public function destroy(Request $request, ResidenteService $residentes, Residente $residente): RedirectResponse
    {
        abort_unless($residente->activo, 404);

        $residentes->darDeBaja(
            $request->user(),
            $residente,
            $request->filled('vivienda') ? $request->integer('vivienda') : null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $residente->activo ? 'Residente retirado de la vivienda.' : 'Residente dado de baja.',
        ]);

        return back();
    }
}
