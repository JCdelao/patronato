<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\AutorizadoFrecuenteService;
use Patronato\Negocio\Services\VisitaService;
use Patronato\Persistencia\Models\User;

class AutorizadoFrecuenteController extends Controller
{
    public function __construct(private AutorizadoFrecuenteService $autorizados) {}

    public function create(Request $request): Response
    {
        return Inertia::render('vigilancia/autorizados-frecuentes', [
            'viviendas' => $this->autorizados->viviendas($this->usuario($request)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array{nombre: string, tipo: string, dias: list<int|string>, hora_inicio: string, hora_fin: string, viviendas: list<int|string>} $datos */
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'tipo' => ['required', 'string', 'max:40'],
            'dias' => ['required', 'array', 'list', 'min:1'],
            'dias.*' => ['required', 'integer', 'between:1,7'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i'],
            'viviendas' => ['required', 'array', 'list', 'min:1'],
            'viviendas.*' => ['required', 'integer', 'min:1'],
        ], [
            'dias.required' => 'Elige al menos un día.',
            'dias.*.between' => 'Elige únicamente días válidos.',
            'viviendas.required' => 'Elige al menos una vivienda.',
            'hora_inicio.date_format' => 'Indica una hora de inicio válida.',
            'hora_fin.date_format' => 'Indica una hora de fin válida.',
        ]);
        $creada = $this->autorizados->crear(
            $this->usuario($request), $datos['nombre'], $datos['tipo'],
            array_map('intval', $datos['dias']), $datos['hora_inicio'], $datos['hora_fin'],
            array_map('intval', $datos['viviendas']),
        );
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $creada ? 'Autorización frecuente registrada.' : 'Ya existe esta autorización frecuente activa.',
        ]);

        return to_route('vigilancia.autorizados-frecuentes.create');
    }

    public function index(Request $request, int $vivienda, VisitaService $visitas): JsonResponse
    {
        $usuario = $this->usuario($request);
        abort_if($usuario->motivoSinAcceso() !== null, 403);
        $guardia = $visitas->guardia($usuario);

        return response()->json($this->autorizados->deVivienda($guardia, $vivienda))
            ->header('Cache-Control', 'private, no-store');
    }

    private function usuario(Request $request): User
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);

        return $usuario;
    }
}
