<?php

namespace Patronato\Modules\Padron\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Modules\Finanzas\Services\TarifaService;
use Patronato\Modules\Padron\Controllers\Requests\GuardarViviendaRequest;
use Patronato\Modules\Padron\Models\Vivienda;
use Patronato\Modules\Padron\Models\ViviendaResidente;
use Patronato\Modules\Padron\Services\BusquedaService;
use Patronato\Modules\Padron\Services\ViviendaService;

class ViviendaController extends Controller
{
    /**
     * Muestra el padrón de viviendas activas o inactivas del residencial
     */
    public function index(Request $request, TarifaService $tarifas): Response
    {
        $buscar = $request->string('buscar')->trim()->toString();
        $inactivas = $request->string('estado')->toString() === 'inactivas';

        $viviendas = Vivienda::query()
            ->where('activa', ! $inactivas)
            ->when($buscar !== '', function (Builder $query) use ($buscar) {
                $patron = BusquedaService::patron($buscar);

                $query->where(function (Builder $query) use ($patron) {
                    $query->whereRaw(BusquedaService::columna('codigo').' like ?', [$patron])
                        ->orWhereHas('vinculosVigentes.residente', function (Builder $query) use ($patron) {
                            $query->whereRaw(BusquedaService::columna("nombres || ' ' || apellidos").' like ?', [$patron])
                                ->orWhereRaw(BusquedaService::columna('telefono').' like ?', [$patron]);
                        });
                });
            })
            ->with('vinculosVigentes.residente.vinculosVigentes.vivienda')
            ->orderBy('codigo')
            ->paginate(24)
            ->withQueryString();

        /** @var array<int, int> $ids */
        $ids = $viviendas->getCollection()->pluck('id')->all();
        $cuotas = $tarifas->cuotasVigentes($ids);

        return Inertia::render('padron/viviendas', [
            'viviendas' => $viviendas->through(fn (Vivienda $vivienda) => [
                'id' => $vivienda->id,
                'codigo' => $vivienda->codigo,
                'tipo' => $vivienda->tipo,
                'edificio_torre' => $vivienda->edificio_torre,
                'calle_bloque' => $vivienda->calle_bloque,
                'numero' => $vivienda->numero,
                'estado_ocupacion' => $vivienda->estado_ocupacion,
                'cuota' => $cuotas[$vivienda->id] ?? null,
                'residentes' => $vivienda->vinculosVigentes
                    ->sortByDesc('es_responsable_pago')
                    ->values()
                    ->map(fn (ViviendaResidente $vinculo) => [
                        'id' => $vinculo->residente_id,
                        'vivienda_id' => $vinculo->vivienda_id,
                        'tipo' => $vinculo->tipo,
                        'es_responsable_pago' => $vinculo->es_responsable_pago,
                        'nombres' => $vinculo->residente->nombres,
                        'apellidos' => $vinculo->residente->apellidos,
                        'identidad' => $vinculo->residente->identidad,
                        'telefono' => $vinculo->residente->telefono,
                        'correo' => $vinculo->residente->correo,
                        'otras_viviendas' => $vinculo->residente->vinculosVigentes
                            ->where('vivienda_id', '!=', $vinculo->vivienda_id)
                            ->map(fn (ViviendaResidente $otro) => $otro->vivienda->codigo)
                            ->sort()
                            ->values(),
                    ]),
            ]),
            'opcionesViviendas' => Vivienda::query()
                ->where('activa', true)
                ->orderBy('codigo')
                ->get(['id', 'codigo']),
            'buscar' => $buscar,
            'estado' => $inactivas ? 'inactivas' : 'activas',
        ]);
    }

    /**
     * Registra una vivienda
     */
    public function store(GuardarViviendaRequest $request, ViviendaService $viviendas): RedirectResponse
    {
        /** @var array{codigo: string, tipo: string, edificio_torre?: string|null, calle_bloque?: string|null, numero: string, estado_ocupacion: string, cuota: string} $datos */
        $datos = $request->validated();

        $viviendas->registrar($request->user(), $datos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vivienda registrada.']);

        return back();
    }

    /**
     * Actualiza una vivienda del residencial
     */
    public function update(GuardarViviendaRequest $request, ViviendaService $viviendas, Vivienda $vivienda): RedirectResponse
    {
        abort_unless($vivienda->activa, 404);

        /** @var array{codigo: string, tipo: string, edificio_torre?: string|null, calle_bloque?: string|null, numero: string, estado_ocupacion: string, cuota: string} $datos */
        $datos = $request->validated();

        $viviendas->actualizar($request->user(), $vivienda, $datos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vivienda actualizada.']);

        return back();
    }

    /**
     * Elimina una vivienda o la deja inactiva
     */
    public function destroy(Request $request, ViviendaService $viviendas, Vivienda $vivienda): RedirectResponse
    {
        abort_unless($vivienda->activa, 404);

        try {
            $borrada = $viviendas->eliminar($request->user(), $vivienda);
        } catch (ValidationException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $borrada ? 'Vivienda eliminada.' : 'La vivienda tiene historial: quedó en Inactivas.',
        ]);

        return back();
    }

    /**
     * Reactiva una vivienda
     */
    public function reactivar(Request $request, ViviendaService $viviendas, Vivienda $vivienda): RedirectResponse
    {
        abort_if($vivienda->activa, 404);

        $viviendas->reactivar($request->user(), $vivienda);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Vivienda {$vivienda->codigo} reactivada."]);

        return back();
    }
}
