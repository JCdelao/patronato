<?php

namespace Patronato\Modules\Identidad\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Modules\Identidad\Controllers\Requests\GuardarCuentaRequest;
use Patronato\Modules\Identidad\Models\Rol;
use Patronato\Modules\Identidad\Services\CuentaService;
use Patronato\Modules\Padron\Services\ResidenteService;
use Patronato\Modules\Plataforma\Services\ConfiguracionService;

class CuentaController extends Controller
{
    /**
     * Muestra las cuentas del residencial
     */
    public function index(Request $request, CuentaService $cuentas, ResidenteService $residentes, ConfiguracionService $configuracion): Response
    {
        $actor = $request->user();
        $credenciales = $request->session()->get('credenciales');
        $residentesDisponibles = $residentes->paraCuentas();

        return Inertia::render('identidad/usuarios', [
            'cuentas' => User::query()
                ->where('residencial_id', $actor->residencial_id)
                ->with('rol')
                ->orderBy('name')
                ->get()
                ->map(function (User $cuenta) use ($actor, $residentesDisponibles) {
                    $residente = collect($residentesDisponibles)->firstWhere('user_id', $cuenta->id);

                    return [
                        'id' => $cuenta->id,
                        'name' => $cuenta->name,
                        'email' => $cuenta->email,
                        'rol_id' => $cuenta->rol_id,
                        'rol' => $cuenta->rol?->nombre,
                        'activo' => $cuenta->activo,
                        'residente_id' => $residente['id'] ?? null,
                        'vivienda' => $residente['vivienda'] ?? null,
                        'editable' => $this->esEditable($actor, $cuenta),
                    ];
                }),
            'roles' => $cuentas->rolesAsignables()->map(fn (Rol $rol) => [
                'id' => $rol->id,
                'codigo' => $rol->codigo,
                'nombre' => $rol->nombre,
            ])->values(),
            'residentes' => $residentesDisponibles,
            'parametros' => $configuracion->seguridad($actor->residencial_id),
            'puedeConfigurarAccesos' => $actor->can('configurar-accesos'),
            'credenciales' => is_string($credenciales) ? Crypt::decrypt($credenciales) : null,
        ]);
    }

    /**
     * Crea una cuenta
     */
    public function store(GuardarCuentaRequest $request, CuentaService $cuentas): RedirectResponse
    {
        /** @var array{name: string, email: string, rol_id: string, residente_id?: string|null} $datos */
        $datos = $request->validated();

        $cuenta = $cuentas->crear($request->user(), $datos);

        if ($cuenta['correo_enviado']) {
            Inertia::flash('toast', ['type' => 'success', 'message' => "Cuenta creada. Se envió la contraseña temporal a {$cuenta['usuario']->email}."]);

            return back();
        }

        // Guarda la contraseña temporal cifrada en la sesión
        $request->session()->flash('credenciales', Crypt::encrypt([
            'correo' => $cuenta['usuario']->email,
            'password_temporal' => $cuenta['password_temporal'],
        ]));

        Inertia::flash('toast', ['type' => 'error', 'message' => 'Cuenta creada, pero no se pudo enviar el correo.']);

        return back();
    }

    /**
     * Actualiza una cuenta del residencial
     */
    public function update(GuardarCuentaRequest $request, CuentaService $cuentas, User $cuenta): RedirectResponse
    {
        abort_unless($this->esEditable($request->user(), $cuenta), 403, 'Esta cuenta no se puede modificar desde aquí');

        /** @var array{name: string, email: string, rol_id: string, residente_id?: string|null} $datos */
        $datos = $request->validated();

        $cuentas->actualizar($request->user(), $cuenta, $datos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cuenta actualizada.']);

        return back();
    }

    /**
     * Desactiva una cuenta
     */
    public function desactivar(Request $request, CuentaService $cuentas, User $cuenta): RedirectResponse
    {
        abort_unless($this->esEditable($request->user(), $cuenta), 403, 'Esta cuenta no se puede modificar desde aquí');

        $cuentas->cambiarActivo($request->user(), $cuenta, false);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cuenta desactivada.']);

        return back();
    }

    /**
     * Reactiva una cuenta
     */
    public function activar(Request $request, CuentaService $cuentas, User $cuenta): RedirectResponse
    {
        abort_unless($this->esEditable($request->user(), $cuenta), 403, 'Esta cuenta no se puede modificar desde aquí');

        $cuentas->cambiarActivo($request->user(), $cuenta, true);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cuenta activada.']);

        return back();
    }

    /**
     * Guarda los parámetros de acceso del residencial
     */
    public function parametros(Request $request, ConfiguracionService $configuracion): RedirectResponse
    {
        /** @var array{max_intentos_login: string, minutos_bloqueo_login: string, minutos_inactividad_sesion: string} $datos */
        $datos = $request->validate([
            'max_intentos_login' => ['required', 'integer', 'min:1', 'max:20'],
            'minutos_bloqueo_login' => ['required', 'integer', 'min:1', 'max:1440'],
            'minutos_inactividad_sesion' => ['required', 'integer', 'min:5', 'max:1440'],
        ]);

        $configuracion->actualizarSeguridad((int) $request->user()->residencial_id, $datos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Parámetros guardados.']);

        return back();
    }

    /**
     * Indica si la cuenta se puede administrar
     */
    private function esEditable(User $actor, User $cuenta): bool
    {
        return $cuenta->id !== $actor->id && $cuenta->rol?->codigo !== Rol::ADMIN_TECNICO;
    }
}
