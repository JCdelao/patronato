<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExigirCambioPassword
{
    /**
     * Impide usar el sistema hasta reemplazar la contraseña temporal
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->debe_cambiar_password
            && ! $request->routeIs('password-temporal.edit', 'password-temporal.update', 'logout')) {
            return to_route('password-temporal.edit');
        }

        return $next($request);
    }
}
