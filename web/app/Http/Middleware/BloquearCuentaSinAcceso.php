<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class BloquearCuentaSinAcceso
{
    /**
     * Cierra la sesión de quien ya estaba dentro cuando su residencial se suspendió
     * o su cuenta se desactivó (HU-03, HU-06).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $motivo = $request->user()?->motivoSinAcceso();

        if ($motivo !== null) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login')->with('status', $motivo);
        }

        return $next($request);
    }
}
