<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Patronato\Negocio\Services\CategoriaFinancieraService;
use Patronato\Persistencia\Models\CategoriaFinanciera;
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

        $categoria = $categorias->registrar($usuario, CategoriaFinanciera::EGRESO, (string) $request->input('nombre'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Categoría {$categoria->nombre} agregada."]);

        return back();
    }

    /**
     * Quita una categoría de egreso: se borra si nunca se usó y se desactiva si ya tiene historial
     */
    public function quitar(Request $request, CategoriaFinancieraService $categorias, CategoriaFinanciera $categoria): RedirectResponse
    {
        abort_unless($categoria->tipo === CategoriaFinanciera::EGRESO, 404);

        /** @var User $usuario */
        $usuario = $request->user();

        $borrada = $categorias->quitarDeEgreso($usuario, $categoria);

        Inertia::flash('toast', ['type' => 'success', 'message' => $borrada
            ? "Categoría {$categoria->nombre} eliminada."
            : "Categoría {$categoria->nombre} desactivada; su historial se conserva."]);

        return back();
    }

    /**
     * Vuelve a ofrecer una categoría de egreso desactivada
     */
    public function reactivar(Request $request, CategoriaFinancieraService $categorias, CategoriaFinanciera $categoria): RedirectResponse
    {
        abort_unless($categoria->tipo === CategoriaFinanciera::EGRESO, 404);

        /** @var User $usuario */
        $usuario = $request->user();

        $categorias->reactivarDeEgreso($usuario, $categoria);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Categoría {$categoria->nombre} reactivada."]);

        return back();
    }
}
