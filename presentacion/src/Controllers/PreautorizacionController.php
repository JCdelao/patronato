<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Patronato\Negocio\Services\PreautorizacionService;
use Patronato\Negocio\Services\VisitaService;
use Patronato\Persistencia\Models\User;

class PreautorizacionController extends Controller
{
    public function index(Request $request, int $vivienda, VisitaService $visitas, PreautorizacionService $preautorizaciones): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);
        $guardia = $visitas->guardia($usuario);
        $request->validate(['fecha' => ['sometimes', 'required', 'date_format:Y-m-d']]);

        return response()->json($preautorizaciones->delDia(
            $guardia,
            $vivienda,
            $request->has('fecha') ? $request->string('fecha')->toString() : null,
        ))->header('Cache-Control', 'private, no-store');
    }
}
