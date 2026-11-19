<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\CarnetService;
use Patronato\Persistencia\Models\Carnet;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\CambiarEstadoCredencialRequest;
use Patronato\Presentacion\Requests\DarDeBajaRequest;
use Patronato\Presentacion\Requests\EmitirCarnetRequest;
use Patronato\Presentacion\Requests\RenovarCarnetRequest;

class CarnetController extends Controller
{
    public function __construct(private CarnetService $carnets) {}

    /**
     * Residentes activos con su carnet vigente y su historial
     */
    public function index(Request $request): Response
    {
        $buscar = $request->string('buscar')->trim()->toString();

        return Inertia::render('carnets/index', [
            'buscar' => $buscar,
            'residentes' => $this->carnets->listado($buscar),
            'vigenciaPredeterminada' => $this->carnets->vigenciaPredeterminada(),
        ]);
    }

    /**
     * Emite el carnet del residente con el siguiente número
     */
    public function emitir(EmitirCarnetRequest $request, Residente $residente): RedirectResponse
    {
        $carnet = $this->carnets->emitir($this->usuario($request), $residente, (string) $request->input('vigente_hasta'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Carnet {$carnet->numero} emitido."]);

        return back();
    }

    /**
     * Sustituye el carnet por uno nuevo con otra vigencia
     */
    public function renovar(RenovarCarnetRequest $request, Carnet $carnet): RedirectResponse
    {
        $nuevo = $this->carnets->renovar($this->usuario($request), $carnet, (string) $request->input('vigente_hasta'), (string) $request->input('motivo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Carnet {$carnet->numero} renovado como {$nuevo->numero}."]);

        return back();
    }

    /**
     * Suspende, reactiva o marca como vencido el carnet
     */
    public function cambiarEstado(CambiarEstadoCredencialRequest $request, Carnet $carnet): RedirectResponse
    {
        $this->carnets->cambiarEstado($this->usuario($request), $carnet, (string) $request->input('accion'), (string) $request->input('motivo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Carnet {$carnet->numero} actualizado."]);

        return back();
    }

    /**
     * Retira el carnet definitivamente
     */
    public function baja(DarDeBajaRequest $request, Carnet $carnet): RedirectResponse
    {
        $this->carnets->darDeBaja($this->usuario($request), $carnet, (string) $request->input('motivo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Carnet {$carnet->numero} dado de baja."]);

        return back();
    }

    private function usuario(Request $request): User
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return $usuario;
    }
}
