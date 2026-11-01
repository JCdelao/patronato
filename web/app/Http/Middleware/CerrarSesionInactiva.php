<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Patronato\Modules\Plataforma\Services\ConfiguracionService;
use Symfony\Component\HttpFoundation\Response;

class CerrarSesionInactiva
{
    public function __construct(private ConfiguracionService $configuracion) {}

    /**
     * Cierra la sesión tras el tiempo de inactividad configurado
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario === null) {
            return $next($request);
        }

        $ultima = $request->session()->get('ultima_actividad');
        $limite = $this->configuracion->seguridad($usuario->residencial_id)['minutos_inactividad_sesion'] * 60;

        if (is_int($ultima) && now()->getTimestamp() - $ultima > $limite) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login')->with('status', 'Tu sesión se cerró por inactividad. Vuelve a iniciar sesión.');
        }

        $request->session()->put('ultima_actividad', now()->getTimestamp());

        return $next($request);
    }
}
