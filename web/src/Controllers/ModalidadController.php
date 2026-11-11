<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\ModalidadService;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Persistencia\Models\ModalidadCobro;
use Patronato\Presentacion\Requests\GuardarModalidadRequest;

class ModalidadController extends Controller
{
    /**
     * Muestra la modalidad de cobro del residencial
     */
    public function index(Request $request, ModalidadService $modalidades, PermisoService $permisos): Response
    {
        $usuario = $request->user();
        $residencialId = (int) $usuario->residencial_id;

        return Inertia::render('finanzas/modalidad', [
            'actual' => $this->datos($modalidades->vigente($residencialId, $modalidades->periodoActual())),
            'programada' => $this->datos($modalidades->programada($residencialId)),
            'periodoActual' => $modalidades->periodoActual()->toDateString(),
            'puedeEditar' => $permisos->puede($usuario, 'parametros', 'registrar'),
        ]);
    }

    /**
     * Guarda la modalidad de cobro
     */
    public function update(GuardarModalidadRequest $request, ModalidadService $modalidades): RedirectResponse
    {
        /** @var array{modalidad: string, desglose?: array<int, array{concepto: string, monto: string}>} $datos */
        $datos = $request->validated();

        $desde = $modalidades->guardar($request->user(), $datos['modalidad'], $datos['desglose'] ?? []);

        $mensaje = $desde->isSameMonth($modalidades->periodoActual())
            ? 'Modalidad guardada.'
            : 'El cambio se aplicará desde '.$desde->translatedFormat('F \d\e Y').'.';

        Inertia::flash('toast', ['type' => 'success', 'message' => $mensaje]);

        return back();
    }

    /**
     * @return array{modalidad: string, desde: string, desglose: list<array{concepto: string, monto: string}>}|null
     */
    private function datos(?ModalidadCobro $fila): ?array
    {
        return $fila === null ? null : [
            'modalidad' => $fila->modalidad,
            'desde' => $fila->vigente_desde->toDateString(),
            'desglose' => $fila->desglose ?? [],
        ];
    }
}
