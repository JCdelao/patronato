<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Patronato\Negocio\Services\CategoriaFinancieraService;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\RegistrarCategoriaEgresoRequest;

class CategoriaEgresoController extends Controller
{
    /**
     * Registra una categoría de egreso propia del residencial
     */
    public function store(RegistrarCategoriaEgresoRequest $request, CategoriaFinancieraService $categorias): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $categoria = $categorias->registrarDeEgreso($usuario, (string) $request->input('nombre'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Categoría {$categoria->nombre} agregada."]);

        return back();
    }
}
