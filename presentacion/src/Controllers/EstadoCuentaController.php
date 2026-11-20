<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\EstadoCuentaService;
use Patronato\Persistencia\Models\Vivienda;

class EstadoCuentaController extends Controller
{
    public function __construct(private EstadoCuentaService $estados) {}

    /**
     * Busca la vivienda cuyo estado de cuenta se quiere consultar
     */
    public function index(Request $request): Response
    {
        $buscar = $request->string('buscar')->trim()->toString();

        return Inertia::render('finanzas/estado-cuenta', [
            'buscar' => $buscar,
            'viviendas' => $this->estados->buscar($buscar),
            'estado' => null,
        ]);
    }

    /**
     * Muestra el estado de cuenta de la vivienda, completo o de un año
     *
     * Una vivienda de otro residencial no se encuentra por el filtro del modelo.
     */
    public function show(Request $request, Vivienda $vivienda): Response
    {
        $anio = $request->integer('anio') ?: null;

        return Inertia::render('finanzas/estado-cuenta', [
            'buscar' => '',
            'viviendas' => [],
            'estado' => $this->estados->deVivienda($vivienda, $anio),
        ]);
    }
}
