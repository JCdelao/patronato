<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\GuardiaService;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\GuardarGuardiaRequest;

class GuardiaController extends Controller
{
    public function __construct(private GuardiaService $guardias) {}

    /**
     * Lista los guardias activos o los inactivos
     */
    public function index(Request $request): Response
    {
        $buscar = $request->string('buscar')->trim()->toString();
        $estado = $request->string('estado')->toString() === 'inactivos' ? 'inactivos' : 'activos';
        $credenciales = $request->session()->get('credenciales');

        return Inertia::render('vigilancia/guardias', [
            'estado' => $estado,
            'buscar' => $buscar,
            'guardias' => $this->guardias->listado($estado === 'activos', $buscar),
            'turnos' => Guardia::TURNOS,
            'credenciales' => is_string($credenciales) ? Crypt::decrypt($credenciales) : null,
        ]);
    }

    /**
     * Registra un guardia
     */
    public function store(GuardarGuardiaRequest $request): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        /** @var array{nombre: string, identidad: string, telefono: string, turno: string} $datos */
        $datos = $request->validated();

        $guardia = $this->guardias->registrar($usuario, $datos);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Guardia {$guardia->nombre} registrado."]);

        return back();
    }

    /**
     * Corrige los datos de un guardia activo
     */
    public function update(GuardarGuardiaRequest $request, Guardia $guardia): RedirectResponse
    {
        abort_unless($guardia->activo, 404);

        /** @var User $usuario */
        $usuario = $request->user();

        /** @var array{nombre: string, identidad: string, telefono: string, turno: string} $datos */
        $datos = $request->validated();

        $this->guardias->actualizar($usuario, $guardia, $datos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Guardia actualizado.']);

        return back();
    }

    /**
     * Desactiva el guardia y su cuenta
     */
    public function desactivar(Request $request, Guardia $guardia): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $this->guardias->cambiarActivo($usuario, $guardia, false);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Guardia {$guardia->nombre} desactivado."]);

        return back();
    }

    /**
     * Reactiva el guardia y su cuenta
     */
    public function activar(Request $request, Guardia $guardia): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $this->guardias->cambiarActivo($usuario, $guardia, true);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Guardia {$guardia->nombre} activado."]);

        return back();
    }

    /**
     * Crea la cuenta del guardia con el rol guardia
     */
    public function crearCuenta(Request $request, Guardia $guardia): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim($request->string('email')->toString()))]);

        /** @var array{email: string} $datos */
        $datos = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ], [
            'email.required' => 'Escribe el correo del guardia.',
            'email.unique' => 'Ya existe una cuenta con ese correo.',
        ]);

        /** @var User $usuario */
        $usuario = $request->user();

        $cuenta = $this->guardias->crearCuenta($usuario, $guardia, $datos['email']);

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
}
