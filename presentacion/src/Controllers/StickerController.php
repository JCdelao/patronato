<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Patronato\Negocio\Services\StickerService;
use Patronato\Persistencia\Models\Sticker;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vehiculo;
use Patronato\Presentacion\Requests\AsignarStickerRequest;
use Patronato\Presentacion\Requests\CambiarEstadoCredencialRequest;
use Patronato\Presentacion\Requests\DarDeBajaRequest;
use Patronato\Presentacion\Requests\ReemplazarStickerRequest;

class StickerController extends Controller
{
    public function __construct(private StickerService $stickers) {}

    /**
     * Asigna un sticker al vehículo
     */
    public function asignar(AsignarStickerRequest $request, Vehiculo $vehiculo): RedirectResponse
    {
        $sticker = $this->stickers->asignar($this->usuario($request), $vehiculo, (string) $request->input('numero'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sticker {$sticker->numero} asignado a {$vehiculo->identificador()}."]);

        return back();
    }

    /**
     * Sustituye el sticker por uno nuevo con su motivo
     */
    public function reemplazar(ReemplazarStickerRequest $request, Sticker $sticker): RedirectResponse
    {
        $nuevo = $this->stickers->reemplazar($this->usuario($request), $sticker, (string) $request->input('numero'), (string) $request->input('motivo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sticker {$sticker->numero} reemplazado por {$nuevo->numero}."]);

        return back();
    }

    /**
     * Suspende, reactiva o marca como vencido el sticker
     */
    public function cambiarEstado(CambiarEstadoCredencialRequest $request, Sticker $sticker): RedirectResponse
    {
        $this->stickers->cambiarEstado($this->usuario($request), $sticker, (string) $request->input('accion'), (string) $request->input('motivo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sticker {$sticker->numero} actualizado."]);

        return back();
    }

    /**
     * Retira el sticker definitivamente
     */
    public function baja(DarDeBajaRequest $request, Sticker $sticker): RedirectResponse
    {
        $this->stickers->darDeBaja($this->usuario($request), $sticker, (string) $request->input('motivo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sticker {$sticker->numero} dado de baja."]);

        return back();
    }

    private function usuario(AsignarStickerRequest|ReemplazarStickerRequest|CambiarEstadoCredencialRequest|DarDeBajaRequest $request): User
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return $usuario;
    }
}
