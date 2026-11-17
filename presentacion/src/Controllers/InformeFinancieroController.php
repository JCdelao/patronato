<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\InformeFinancieroService;
use Patronato\Negocio\Services\PeriodoService;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\FiltrarInformeFinancieroRequest;
use Patronato\Presentacion\Requests\RegistrarAperturaRequest;
use Symfony\Component\HttpFoundation\Response as RespuestaHttp;

class InformeFinancieroController extends Controller
{
    public function __construct(private InformeFinancieroService $informes) {}

    /**
     * Muestra el informe del mes o del año elegido
     */
    public function index(FiltrarInformeFinancieroRequest $request, PeriodoService $periodos): Response
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $periodo = $request->periodo($this->informes->periodoPredeterminado());

        return Inertia::render('reportes/financiero', [
            'periodo' => $periodo,
            'hoy' => $periodos->hoy()->toDateString(),
            'apertura' => $this->informes->apertura($usuario),
            'informe' => $this->informes->generar($usuario, $periodo)->paraPantalla(),
        ]);
    }

    /**
     * Descarga el informe en PDF o Excel con el mismo período
     */
    public function exportar(FiltrarInformeFinancieroRequest $request, string $formato): RespuestaHttp
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $archivo = $this->informes->exportar($usuario, $request->periodo($this->informes->periodoPredeterminado()), $formato);

        return response($archivo['contenido'], 200, [
            'Content-Type' => $archivo['tipo'],
            'Content-Disposition' => 'attachment; filename="'.$archivo['nombre'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Registra o corrige el saldo de apertura del residencial
     */
    public function apertura(RegistrarAperturaRequest $request): RedirectResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $this->informes->registrarApertura($usuario, (string) $request->input('monto'), (string) $request->input('fecha'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saldo de apertura guardado.']);

        return back();
    }
}
