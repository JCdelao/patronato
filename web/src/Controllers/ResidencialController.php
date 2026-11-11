<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\AuditoriaService;
use Patronato\Negocio\Services\ResidencialService;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Presentacion\Requests\RegistrarResidencialRequest;

class ResidencialController extends Controller
{
    /**
     * Muestra los residenciales registrados en la plataforma
     */
    public function index(Request $request, AuditoriaService $auditoria, ResidencialService $servicio): Response
    {
        $credenciales = $request->session()->get('credenciales');

        $residenciales = $servicio->todos();

        /** @var array<int, int> $ids */
        $ids = $residenciales->pluck('id')->all();
        $suspensiones = $auditoria->ultimosPorEntidad('residenciales', $ids, 'residencial_'.Residencial::SUSPENDIDO);

        return Inertia::render('plataforma/residenciales/index', [
            'residenciales' => $residenciales
                ->map(fn (Residencial $residencial) => [
                    'id' => $residencial->id,
                    'nombre' => $residencial->nombre,
                    'direccion' => $residencial->direccion,
                    'telefono' => $residencial->telefono,
                    'correo' => $residencial->correo,
                    'estado' => $residencial->estado,
                    'motivo_suspension' => $residencial->estado === Residencial::SUSPENDIDO
                        ? ($suspensiones[$residencial->id]->motivo ?? null)
                        : null,
                    'logo_url' => $residencial->logo_path
                        ? Storage::disk('public')->url($residencial->logo_path)
                        : null,
                ]),
            'credenciales' => is_string($credenciales) ? Crypt::decrypt($credenciales) : null,
        ]);
    }

    /**
     * Muestra el formulario para registrar un residencial
     */
    public function create(): Response
    {
        return Inertia::render('plataforma/residenciales/create');
    }

    /**
     * Registra un residencial
     */
    public function store(RegistrarResidencialRequest $request, ResidencialService $residenciales): RedirectResponse
    {
        /** @var array{nombre: string, direccion?: string|null, telefono?: string|null, correo?: string|null, admin_nombre: string, admin_correo: string} $datos */
        $datos = $request->safe()->except('logo');

        $registro = $residenciales->registrar($datos, $request->file('logo'));

        if ($registro['correo_enviado']) {
            Inertia::flash('toast', ['type' => 'success', 'message' => "Residencial registrado. Se envió la contraseña temporal a {$registro['administrador']->email}."]);

            return to_route('plataforma.residenciales.index');
        }

        // Guarda la contraseña temporal cifrada en la sesión
        $request->session()->flash('credenciales', Crypt::encrypt([
            'residencial' => $registro['residencial']->nombre,
            'correo' => $registro['administrador']->email,
            'password_temporal' => $registro['password_temporal'],
        ]));

        Inertia::flash('toast', ['type' => 'error', 'message' => 'Residencial registrado, pero no se pudo enviar el correo.']);

        return to_route('plataforma.residenciales.index');
    }

    /**
     * Suspende un residencial
     */
    public function suspender(Request $request, ResidencialService $residenciales, Residencial $residencial): RedirectResponse
    {
        return $this->cambiarEstado($request, $residenciales, $residencial, Residencial::SUSPENDIDO, 'Residencial suspendido.');
    }

    /**
     * Reactiva un residencial
     */
    public function reactivar(Request $request, ResidencialService $residenciales, Residencial $residencial): RedirectResponse
    {
        return $this->cambiarEstado($request, $residenciales, $residencial, Residencial::ACTIVO, 'Residencial reactivado.');
    }

    private function cambiarEstado(Request $request, ResidencialService $residenciales, Residencial $residencial, string $estado, string $mensaje): RedirectResponse
    {
        /** @var array{motivo: string} $datos */
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        if ($residencial->estado === $estado) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'El residencial ya está en ese estado.']);

            return back();
        }

        $residenciales->cambiarEstado($residencial, $estado, $datos['motivo'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $mensaje]);

        return back();
    }
}
