<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\CategoriaFinancieraService;
use Patronato\Negocio\Services\IngresoService;
use Patronato\Negocio\Services\PeriodoService;
use Patronato\Persistencia\Models\CategoriaFinanciera;
use Patronato\Persistencia\Models\Ingreso;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\AnularMovimientoRequest;
use Patronato\Presentacion\Requests\RegistrarIngresoRequest;

class IngresoController extends Controller
{
    public function __construct(private IngresoService $ingresos) {}

    /**
     * Lista los ingresos del residencial: los de cobros y los registrados a mano
     */
    public function index(Request $request): Response
    {
        $buscar = $request->string('buscar')->trim()->toString();

        return Inertia::render('finanzas/ingresos', [
            'buscar' => $buscar,
            'ingresos' => $this->ingresos->listado($buscar),
        ]);
    }

    /**
     * Formulario de un ingreso nuevo
     */
    public function create(CategoriaFinancieraService $categorias, PeriodoService $periodos): Response
    {
        return Inertia::render('finanzas/ingreso-nuevo', [
            'categorias' => $categorias->deIngresosManuales()->map(fn (CategoriaFinanciera $categoria): array => [
                'id' => $categoria->id,
                'nombre' => $categoria->nombre,
            ])->values(),
            'hoy' => $periodos->hoy()->toDateString(),
        ]);
    }

    /**
     * Registra un ingreso que no viene de un cobro
     */
    public function store(RegistrarIngresoRequest $request): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        /** @var array{fecha: string, concepto: string, categoria_id: int|string, monto: string, numero_comprobante?: string|null} $datos */
        $datos = $request->validated();

        $ingreso = $this->ingresos->registrar($usuario, $datos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ingreso registrado.']);

        return to_route('finanzas.ingresos.show', $ingreso);
    }

    /**
     * Muestra el ingreso, su origen y su anulación
     */
    public function show(Request $request, Ingreso $ingreso): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('finanzas/ingreso', [
            'ingreso' => $this->ingresos->detalle($usuario, $ingreso),
        ]);
    }

    /**
     * Anula el ingreso con su motivo
     */
    public function anular(AnularMovimientoRequest $request, Ingreso $ingreso): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $this->ingresos->anular($usuario, $ingreso, (string) $request->input('motivo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ingreso anulado.']);

        return to_route('finanzas.ingresos.show', $ingreso);
    }
}
