<?php

namespace Patronato\Modules\Identidad\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Modules\Identidad\Models\Auditoria;

class AuditoriaController extends Controller
{
    /**
     * Las fechas de los filtros se entienden en hora de Honduras; la base guarda UTC.
     */
    private const ZONA_LOCAL = 'America/Tegucigalpa';

    /**
     * Muestra la bitácora de auditoría del residencial, solo para consulta (HU-08).
     */
    public function index(Request $request): Response
    {
        $residencialId = $request->user()->residencial_id;

        /** @var array{desde?: string|null, hasta?: string|null, user_id?: string|null, modulo?: string|null, accion?: string|null} $filtros */
        $filtros = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'user_id' => ['nullable', 'string'],
            'modulo' => ['nullable', 'string', 'max:40'],
            'accion' => ['nullable', 'string', 'max:40'],
        ]);

        $aplicar = fn (?string $valor): ?string => $valor === 'todos' || $valor === '' ? null : $valor;

        $usuario = $aplicar($filtros['user_id'] ?? null);
        $modulo = $aplicar($filtros['modulo'] ?? null);
        $accion = $aplicar($filtros['accion'] ?? null);
        $desde = $filtros['desde'] ?? null;
        $hasta = $filtros['hasta'] ?? null;

        $base = Auditoria::query()->where('residencial_id', $residencialId);

        return Inertia::render('identidad/auditoria', [
            'registros' => $base->clone()
                ->with('usuario:id,name')
                ->when($desde, fn ($query, string $fecha) => $query->where('created_at', '>=', Carbon::parse($fecha, self::ZONA_LOCAL)->startOfDay()->utc()))
                ->when($hasta, fn ($query, string $fecha) => $query->where('created_at', '<=', Carbon::parse($fecha, self::ZONA_LOCAL)->endOfDay()->utc()))
                ->when($usuario, fn ($query, string $id) => $query->where('user_id', (int) $id))
                ->when($modulo, fn ($query, string $valor) => $query->where('modulo', $valor))
                ->when($accion, fn ($query, string $valor) => $query->where('accion', $valor))
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (Auditoria $registro) => [
                    'id' => $registro->id,
                    'fecha' => $registro->created_at->toIso8601String(),
                    'usuario' => $registro->usuario->name ?? 'Sistema',
                    'modulo' => $registro->modulo,
                    'accion' => $registro->accion,
                    'entidad' => $registro->entidad,
                    'entidad_id' => $registro->entidad_id,
                    'valor_anterior' => $registro->valor_anterior,
                    'valor_nuevo' => $registro->valor_nuevo,
                    'motivo' => $registro->motivo,
                ]),
            'usuarios' => User::query()
                ->where('residencial_id', $residencialId)
                ->orderBy('name')
                ->get(['id', 'name']),
            'modulos' => $base->clone()->distinct()->orderBy('modulo')->pluck('modulo'),
            'acciones' => $base->clone()->distinct()->orderBy('accion')->pluck('accion'),
            'filtros' => [
                'desde' => $desde,
                'hasta' => $hasta,
                'user_id' => $usuario,
                'modulo' => $modulo,
                'accion' => $accion,
            ],
        ]);
    }
}
