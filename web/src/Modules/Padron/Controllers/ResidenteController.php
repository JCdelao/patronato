<?php

namespace Patronato\Modules\Padron\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Modules\Padron\Controllers\Requests\AgregarViviendaRequest;
use Patronato\Modules\Padron\Controllers\Requests\GuardarResidenteRequest;
use Patronato\Modules\Padron\Models\Residente;
use Patronato\Modules\Padron\Models\Vivienda;
use Patronato\Modules\Padron\Models\ViviendaResidente;
use Patronato\Modules\Padron\Services\ResidenteService;

class ResidenteController extends Controller
{
    /**
     * Muestra los residentes inactivos con las viviendas donde vivieron
     */
    public function index(Request $request): Response
    {
        $buscar = $request->string('buscar')->trim()->toString();

        $residentes = Residente::query()
            ->where('activo', false)
            ->when($buscar !== '', function (Builder $query) use ($buscar) {
                $patron = '%'.Str::lower($buscar).'%';

                $query->where(function (Builder $query) use ($patron) {
                    $query->whereRaw("lower(nombres || ' ' || apellidos) like ?", [$patron])
                        ->orWhereRaw('lower(identidad) like ?', [$patron])
                        ->orWhereRaw('lower(telefono) like ?', [$patron]);
                });
            })
            ->with(['vinculos' => fn ($query) => $query->with('vivienda')->orderByDesc('fecha_inicio')->orderByDesc('id')])
            ->orderBy('nombres')
            ->orderBy('apellidos')
            ->paginate(24)
            ->withQueryString();

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
            'opcionesViviendas' => Vivienda::query()
                ->where('activa', true)
                ->orderBy('codigo')
                ->get(['id', 'codigo']),
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
