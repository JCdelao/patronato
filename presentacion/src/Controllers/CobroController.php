<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Contratos\CargosNoIntegrados;
use Patronato\Negocio\Contratos\CargosPendientes;
use Patronato\Negocio\Services\CajaService;
use Patronato\Negocio\Services\CobroService;
use Patronato\Negocio\Services\MoraService;
use Patronato\Negocio\Services\ViviendaService;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\RegistrarCobroRequest;

class CobroController extends Controller
{
    /**
     * Muestra el registro de un cobro en efectivo
     */
    public function create(Request $request, CajaService $cajas, ViviendaService $viviendas, CargosPendientes $cargos, MoraService $mora): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $buscar = $request->string('buscar')->trim()->toString();
        $viviendaId = $request->integer('vivienda') ?: null;

        $opciones = $buscar === '' ? [] : $viviendas->opcionesActivas($buscar);
        $vivienda = $viviendaId === null ? null : $viviendas->opcionesActivas(viviendaId: $viviendaId)[0] ?? null;

        // Cada vivienda muestra si está al día o en mora
        $estados = $mora->estados((int) $usuario->residencial_id, array_column([...$opciones, ...array_filter([$vivienda])], 'id'));
        $conMora = fn (array $opcion): array => [...$opcion, 'mora' => $estados[$opcion['id']]];
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
            'cajaAbierta' => $cajas->delDia($usuario) !== null,
            'buscar' => $buscar,
            'viviendas' => array_map($conMora, $opciones),
            'vivienda' => $vivienda === null ? null : $conMora($vivienda),
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

        // Desde el registro del pago se descarga o se comparte el comprobante
        return to_route('finanzas.comprobantes.show', $pago);
    }
}
