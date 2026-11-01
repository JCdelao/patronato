<?php

namespace Patronato\Modules\Identidad\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Modules\Identidad\Models\Rol;
use Patronato\Modules\Identidad\Services\CuentaService;
use Patronato\Modules\Identidad\Services\PermisoService;

class PermisoController extends Controller
{
    /**
     * Muestra la matriz de permisos del residencial.
     */
    public function index(Request $request, CuentaService $cuentas, PermisoService $permisos): Response
    {
        $residencialId = (int) $request->user()->residencial_id;

        return Inertia::render('identidad/permisos', [
            'roles' => $cuentas->rolesAsignables()->map(fn (Rol $rol) => [
                'id' => $rol->id,
                'nombre' => $rol->nombre,
                'permisos' => $permisos->deRol($residencialId, $rol->id),
            ])->values(),
            'modulos' => collect(PermisoService::MODULOS)
                ->map(fn (array $definicion, string $modulo) => ['id' => $modulo, ...$definicion])
                ->values(),
        ]);
    }

    /**
     * Guarda los permisos de un rol.
     */
    public function update(Request $request, PermisoService $permisos, CuentaService $cuentas, Rol $rol): RedirectResponse
    {
        abort_unless($cuentas->rolesAsignables()->contains('id', $rol->id), 404);

        /** @var array{permisos?: array<int, string>} $datos */
        $datos = $request->validate([
            'permisos' => ['nullable', 'array'],
            'permisos.*' => ['string', Rule::in($permisos->catalogo())],
        ]);

        $permisos->actualizar($request->user(), $rol, $datos['permisos'] ?? []);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Permisos de {$rol->nombre} guardados."]);

        return back();
    }
}
