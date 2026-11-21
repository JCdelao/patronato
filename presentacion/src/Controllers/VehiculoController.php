<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\VehiculoService;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vehiculo;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Presentacion\Requests\DarDeBajaRequest;
use Patronato\Presentacion\Requests\GuardarVehiculoRequest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VehiculoController extends Controller
{
    public function __construct(private VehiculoService $vehiculos) {}

    /**
     * Lista los vehículos activos o los dados de baja
     */
    public function index(Request $request): Response
    {
        $buscar = $request->string('buscar')->trim()->toString();
        $estado = $request->string('estado')->toString() === 'bajas' ? 'bajas' : 'activos';

        return Inertia::render('vehiculos/index', [
            'estado' => $estado,
            'buscar' => $buscar,
            'vehiculos' => $this->vehiculos->listado($estado === 'activos', $buscar),
            'viviendas' => $this->vehiculos->viviendasActivas(),
            'tipos' => Vehiculo::TIPOS,
            'tiposComprobante' => Vehiculo::TIPOS_COMPROBANTE,
            'hoy' => $this->vehiculos->hoy(),
            // Al llegar desde una vivienda, el formulario la trae elegida
            'nuevoPara' => $request->integer('vivienda') ?: null,
        ]);
    }

    /**
     * Residentes de la vivienda que se pueden autorizar como conductores
     */
    public function conductores(Vivienda $vivienda): JsonResponse
    {
        return response()->json($this->vehiculos->conductoresPosibles($vivienda));
    }

    /**
     * Registra un vehículo en una vivienda
     */
    public function store(GuardarVehiculoRequest $request): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        /** @var array{vivienda_id: int|string, tipo: string, sin_placa?: bool|string|null, placa?: string|null, comprobante_tipo?: string|null, comprobante_descripcion?: string|null, comprobante_numero?: string|null, comprobante_vence?: string|null, marca?: string|null, modelo?: string|null, color?: string|null, conductores?: array<int, int|string>} $datos */
        $datos = $request->safe()->except('comprobante_archivo');

        $vehiculo = $this->vehiculos->registrar($usuario, $datos, $request->file('comprobante_archivo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Vehículo {$vehiculo->identificador()} registrado."]);

        return back();
    }

    /**
     * Corrige los datos y los conductores de un vehículo activo
     */
    public function update(GuardarVehiculoRequest $request, Vehiculo $vehiculo): RedirectResponse
    {
        abort_unless($vehiculo->activo, 404);

        /** @var User $usuario */
        $usuario = $request->user();

        /** @var array{tipo: string, sin_placa?: bool|string|null, placa?: string|null, comprobante_tipo?: string|null, comprobante_descripcion?: string|null, comprobante_numero?: string|null, comprobante_vence?: string|null, marca?: string|null, modelo?: string|null, color?: string|null, conductores?: array<int, int|string>} $datos */
        $datos = $request->safe()->except('comprobante_archivo');

        $this->vehiculos->actualizar($usuario, $vehiculo, $datos, $request->file('comprobante_archivo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vehículo actualizado.']);

        return back();
    }

    /**
     * Abre el comprobante de circulación de un vehículo sin placa
     */
    public function comprobante(Vehiculo $vehiculo): BinaryFileResponse
    {
        $ruta = $this->vehiculos->rutaComprobante($vehiculo);

        abort_if($ruta === null, 404);

        return response()->file($ruta);
    }

    /**
     * Da de baja el vehículo con su motivo
     */
    public function baja(DarDeBajaRequest $request, Vehiculo $vehiculo): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $this->vehiculos->darDeBaja($usuario, $vehiculo, (string) $request->input('motivo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Vehículo {$vehiculo->identificador()} dado de baja."]);

        return back();
    }
}
