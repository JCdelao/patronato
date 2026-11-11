<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\AuditoriaService;
use Patronato\Negocio\Services\BitacoraService;
use Patronato\Negocio\Services\CuentaService;
use Patronato\Persistencia\Models\Auditoria;

class AuditoriaController extends Controller
{
    /**
     * Muestra la bitácora de auditoría
     */
    public function index(Request $request, AuditoriaService $auditoria, BitacoraService $bitacora, CuentaService $cuentas): Response
    {
        $residencialId = (int) $request->user()->residencial_id;

        /** @var array{desde?: string|null, hasta?: string|null, user_id?: string|null, modulo?: string|null, accion?: string|null} $filtros */
        $filtros = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'user_id' => ['nullable', 'string'],
            'modulo' => ['nullable', 'string', 'max:40'],
            'accion' => ['nullable', 'string', 'max:40'],
        ]);

        $aplicar = fn (?string $valor): ?string => $valor === 'todos' || $valor === '' ? null : $valor;

        $filtros = [
            'desde' => $filtros['desde'] ?? null,
            'hasta' => $filtros['hasta'] ?? null,
            'user_id' => $aplicar($filtros['user_id'] ?? null),
            'modulo' => $aplicar($filtros['modulo'] ?? null),
            'accion' => $aplicar($filtros['accion'] ?? null),
        ];

        $registros = $auditoria->buscar($residencialId, $filtros);
        $detalles = $bitacora->presentar($registros->getCollection());

        return Inertia::render('identidad/auditoria', [
            'registros' => $registros->through(fn (Auditoria $registro) => [
                'id' => $registro->id,
                'fecha' => $registro->created_at->toIso8601String(),
                'usuario' => $registro->usuario->name ?? 'Sistema',
                'modulo' => $registro->modulo,
                'accion' => $registro->accion,
                'referencia' => $detalles[$registro->id]['referencia'],
                'cambios' => $detalles[$registro->id]['cambios'],
                'motivo' => $registro->motivo,
            ]),
            'usuarios' => $cuentas->opcionesDelResidencial($residencialId),
            'modulos' => $auditoria->modulos($residencialId),
            'acciones' => $auditoria->acciones($residencialId),
            'filtros' => $filtros,
        ]);
    }
}
