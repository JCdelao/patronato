<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

class RespuestaEnlaceRechazado implements FailedPasswordResetLinkRequestResponse
{
    /**
     * Responde igual que cuando el enlace se envía, para no revelar qué cuentas existen (HU-05).
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $mensaje = trans(Password::RESET_LINK_SENT);

        return $request->wantsJson()
            ? new JsonResponse(['message' => $mensaje])
            : back()->with('status', $mensaje);
    }
}
