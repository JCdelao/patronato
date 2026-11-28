<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\PortalVehiculosService;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vehiculo;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Vehículos, stickers y carnets que el residente consulta desde el portal; es de solo lectura
 */
class PortalVehiculoController extends Controller
{
    public function __construct(private PortalVehiculosService $vehiculos) {}

    public function index(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return Inertia::render('portal/mis-vehiculos', [
            'viviendas' => $this->vehiculos->deUsuario($usuario),
        ]);
    }

    /**
     * Abre el comprobante de circulación de un vehículo sin placa de su vivienda
     */
    public function comprobante(Request $request, Vehiculo $vehiculo): BinaryFileResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $ruta = $this->vehiculos->rutaComprobante($usuario, $vehiculo);

        abort_if($ruta === null, 404);

        return response()->file($ruta, ['Cache-Control' => 'private, no-store']);
    }
}
