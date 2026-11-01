<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

class RespuestaInicioSesion implements LoginResponse, TwoFactorLoginResponse
{
    /**
     * Lleva al usuario a la pantalla de inicio de su rol
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }

        $usuario = $request->user();

        return redirect()->intended(route($usuario instanceof User ? $usuario->rutaInicio() : 'dashboard'));
    }
}
