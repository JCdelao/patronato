<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\CategoriaFinancieraService;
use Patronato\Negocio\Services\EgresoService;
use Patronato\Negocio\Services\PeriodoService;
use Patronato\Persistencia\Models\CategoriaFinanciera;
use Patronato\Persistencia\Models\Egreso;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\AnularEgresoRequest;
use Patronato\Presentacion\Requests\RegistrarEgresoRequest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EgresoController extends Controller
{
    public function __construct(private EgresoService $egresos) {}

    /**
     * Lista los egresos del residencial, incluidos los anulados
     */
    public function index(Request $request): Response
    {
        $buscar = $request->string('buscar')->trim()->toString();

        return Inertia::render('finanzas/egresos', [
            'buscar' => $buscar,
            'egresos' => $this->egresos->listado($buscar),
        ]);
    }

    /**
     * Formulario de un egreso nuevo
     */
    public function create(CategoriaFinancieraService $categorias, PeriodoService $periodos): Response
    {
        return Inertia::render('finanzas/egreso-nuevo', [
            'categorias' => $categorias->deEgresos()->map(fn (CategoriaFinanciera $categoria): array => [
                'id' => $categoria->id,
                'nombre' => $categoria->nombre,
            ])->values(),
            'formas_pago' => Egreso::FORMAS_PAGO,
            'hoy' => $periodos->hoy()->toDateString(),
        ]);
    }

    /**
     * Registra un egreso
     */
    public function store(RegistrarEgresoRequest $request): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        /** @var array{fecha: string, concepto: string, categoria_id: int|string, proveedor?: string|null, monto: string, forma_pago?: string|null, numero_comprobante: string} $datos */
        $datos = $request->safe()->except('factura');

        $egreso = $this->egresos->registrar($usuario, $datos, $request->file('factura'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Egreso registrado.']);

        return to_route('finanzas.egresos.show', $egreso);
    }

    /**
     * Muestra el egreso y su anulación
     */
    public function show(Request $request, Egreso $egreso): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('finanzas/egreso', [
            'egreso' => $this->egresos->detalle($usuario, $egreso),
        ]);
    }

    /**
     * Abre la factura adjunta del egreso
     */
    public function factura(Egreso $egreso): BinaryFileResponse
    {
        $ruta = $this->egresos->rutaFactura($egreso);

        abort_if($ruta === null, 404);

        return response()->file($ruta);
    }

    /**
     * Anula el egreso con su motivo
     */
    public function anular(AnularEgresoRequest $request, Egreso $egreso): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $this->egresos->anular($usuario, $egreso, (string) $request->input('motivo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Egreso anulado.']);

        return to_route('finanzas.egresos.show', $egreso);
    }
}
