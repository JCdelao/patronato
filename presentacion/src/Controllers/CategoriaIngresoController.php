<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Patronato\Negocio\Services\CategoriaFinancieraService;
use Patronato\Persistencia\Models\CategoriaFinanciera;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\RegistrarCategoriaIngresoRequest;

class CategoriaIngresoController extends Controller
{
    /**
     * Registra una categoría de ingreso propia del residencial
     */
    public function store(RegistrarCategoriaIngresoRequest $request, CategoriaFinancieraService $categorias): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $categoria = $categorias->registrar($usuario, CategoriaFinanciera::INGRESO, (string) $request->input('nombre'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Categoría {$categoria->nombre} agregada."]);

        return back();
    }
}
