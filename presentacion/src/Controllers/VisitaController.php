<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\MedioEntradaService;
use Patronato\Negocio\Services\VisitaService;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\RegistrarVisitaRequest;

class VisitaController extends Controller
{
    public function __construct(private VisitaService $visitas, private MedioEntradaService $medios) {}

    public function create(Request $request): Response
    {
        $guardia = $this->visitas->guardia($this->usuario($request));

        return Inertia::render('vigilancia/entrada-visitas', [
            'guardia' => $guardia->nombre,
            'viviendas' => $this->visitas->viviendas($guardia),
            'medios' => $this->medios->listado($guardia->residencial_id, soloActivos: true),
            'identificador' => $request->session()->get('visita_registrada'),
        ]);
    }

    public function store(RegistrarVisitaRequest $request): RedirectResponse
    {
        /** @var array{nombre_visitante: string, identidad: string, motivo: string, vivienda_id: int|string, medio_entrada_id: int|string, placa?: string|null} $datos */
        $datos = $request->validated();
        $visita = $this->visitas->registrar($this->usuario($request), $datos);

        return to_route('vigilancia.visitas.create')->with('visita_registrada', $visita->identificador);
    }

    private function usuario(Request $request): User
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return $usuario;
    }
}
