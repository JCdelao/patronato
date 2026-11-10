<?php

namespace Patronato\Modules\Finanzas\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Modules\Finanzas\Contratos\CargosNoIntegrados;
use Patronato\Modules\Finanzas\Contratos\CargosPendientes;
use Patronato\Modules\Finanzas\Controllers\Requests\RegistrarCobroRequest;
use Patronato\Modules\Finanzas\Services\CajaService;
use Patronato\Modules\Finanzas\Services\CobroService;
use Patronato\Modules\Padron\Services\ViviendaService;

class CobroController extends Controller
{
    /**
     * Muestra el registro de un cobro en efectivo
     */
    public function create(Request $request, CajaService $cajas, ViviendaService $viviendas, CargosPendientes $cargos): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $buscar = $request->string('buscar')->trim()->toString();
        $viviendaId = $request->integer('vivienda') ?: null;

        $vivienda = $viviendaId === null ? null : $viviendas->opcionesActivas(viviendaId: $viviendaId)[0] ?? null;
        $pendientes = null;
        $avisoIntegracion = null;

        if ($vivienda !== null) {
            try {
                $pendientes = $cargos->pendientesDeVivienda($vivienda['id'])->values()->all();
            } catch (CargosNoIntegrados $error) {
                $avisoIntegracion = $error->getMessage();
            }
        }

        return Inertia::render('finanzas/cobro-efectivo', [
            'cajaAbierta' => $cajas->abiertaDe($usuario) !== null,
            'buscar' => $buscar,
            'viviendas' => $buscar === '' ? [] : $viviendas->opcionesActivas($buscar),
            'vivienda' => $vivienda,
            'pendientes' => $pendientes,
            'avisoIntegracion' => $avisoIntegracion,
        ]);
    }

    /**
     * Registra el cobro y muestra el número de recibo
     */
    public function store(RegistrarCobroRequest $request, CobroService $cobros): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        /** @var array<int, int|string> $cargos */
        $cargos = $request->input('cargos');

        $pago = $cobros->registrarEfectivo(
            $usuario,
            $request->integer('vivienda_id'),
            (string) $request->input('monto'),
            array_map(intval(...), $cargos),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Cobro registrado. Recibo N.º {$pago->numero_recibo}."]);

        return to_route('finanzas.cobros.create');
    }
}
