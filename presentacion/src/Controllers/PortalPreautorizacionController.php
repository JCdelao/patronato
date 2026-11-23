<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\MedioEntradaService;
use Patronato\Negocio\Services\PreautorizacionService;
use Patronato\Persistencia\Models\User;

class PortalPreautorizacionController extends Controller
{
    public function create(Request $request, PreautorizacionService $preautorizaciones, MedioEntradaService $medios): Response
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);
        $residente = $preautorizaciones->residente($usuario);

        return Inertia::render('portal/preautorizar-visita', [
            'viviendas' => $preautorizaciones->viviendas($residente),
            'medios' => $medios->listado($residente->residencial_id, soloActivos: true),
        ]);
    }

    public function store(Request $request, PreautorizacionService $preautorizaciones): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);
        $request->validate([
            'vivienda_id' => ['required', 'integer', 'min:1'],
            'medio_entrada_id' => ['required', 'integer', 'min:1'],
            'nombre_visitante' => ['required', 'string', 'max:150'],
            'inicio' => ['required', 'date_format:Y-m-d\TH:i'],
            'fin' => ['required', 'date_format:Y-m-d\TH:i'],
        ], [
            'inicio.date_format' => 'Indica una fecha y hora de inicio válidas.',
            'fin.date_format' => 'Indica una fecha y hora de fin válidas.',
        ]);

        $creada = $preautorizaciones->crear(
            $usuario,
            $request->integer('vivienda_id'),
            $request->integer('medio_entrada_id'),
            $request->string('nombre_visitante')->toString(),
            $request->string('inicio')->toString(),
            $request->string('fin')->toString(),
        );
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $creada ? 'Visita preautorizada.' : 'Esta visita ya estaba preautorizada.',
        ]);

        return to_route('portal.preautorizaciones.create');
    }
}
