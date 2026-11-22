<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\AjusteService;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\RegistrarAjusteRequest;

class AjusteController extends Controller
{
    public function __construct(private AjusteService $ajustes) {}

    /**
     * Busca la vivienda y muestra su saldo, sus ajustes y el formulario de corrección
     */
    public function index(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        // La ruta ya exige el permiso; además el rol debe ser presidente o tesorero
        abort_unless($this->ajustes->puedeRegistrar($usuario), 403);

        $buscar = $request->string('buscar')->trim()->toString();
        $viviendaId = $request->integer('vivienda') ?: null;

        return Inertia::render('finanzas/ajustes', [
            'buscar' => $buscar,
            'viviendas' => $this->ajustes->buscar($buscar),
            'detalle' => $viviendaId === null ? null : $this->ajustes->deVivienda($viviendaId),
        ]);
    }

    /**
     * Registra la corrección y vuelve a la vivienda
     */
    public function store(RegistrarAjusteRequest $request): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $ajuste = $this->ajustes->registrar(
            $usuario,
            $request->integer('vivienda_id'),
            (string) $request->input('sentido'),
            (string) $request->input('monto'),
            (string) $request->input('motivo'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Corrección registrada.']);

        return to_route('finanzas.ajustes.index', ['vivienda' => $ajuste->vivienda_id]);
    }
}
