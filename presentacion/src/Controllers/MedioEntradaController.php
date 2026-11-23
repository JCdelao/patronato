<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\MedioEntradaService;
use Patronato\Persistencia\Models\MedioEntrada;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\GuardarMedioEntradaRequest;

class MedioEntradaController extends Controller
{
    public function __construct(private MedioEntradaService $medios) {}

    public function index(Request $request): Response
    {
        $residencialId = $this->usuario($request)->residencial_id;
        abort_if($residencialId === null, 403);

        return Inertia::render('vigilancia/medios-entrada', ['medios' => $this->medios->listado($residencialId)]);
    }

    public function store(GuardarMedioEntradaRequest $request): RedirectResponse
    {
        return $this->guardar($request);
    }

    public function update(GuardarMedioEntradaRequest $request, MedioEntrada $medio): RedirectResponse
    {
        return $this->guardar($request, $medio);
    }

    private function guardar(GuardarMedioEntradaRequest $request, ?MedioEntrada $medio = null): RedirectResponse
    {
        $this->medios->guardar(
            $this->usuario($request),
            $request->string('nombre')->toString(),
            $request->boolean('requiere_placa'),
            $request->boolean('activo'),
            $medio,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Medio de entrada guardado.']);

        return to_route('vigilancia.medios-entrada.index');
    }

    private function usuario(Request $request): User
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return $usuario;
    }
}
