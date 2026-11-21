<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\IncidenciaService;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Negocio\Services\TipoIncidenciaService;
use Patronato\Negocio\Services\TurnoService;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\Incidencia;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Requests\RegistrarIncidenciaRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidenciaController extends Controller
{
    public function __construct(
        private IncidenciaService $incidencias,
        private TipoIncidenciaService $tipos,
        private TurnoService $turnos,
    ) {}

    /**
     * Incidencias del residencial con el catálogo de tipos
     */
    public function index(Request $request): Response
    {
        $tipoId = $request->integer('tipo') ?: null;
        $urgentes = $request->boolean('urgentes');

        return Inertia::render('vigilancia/incidencias', [
            'tipo' => $tipoId,
            'urgentes' => $urgentes,
            'incidencias' => $this->incidencias->listado($tipoId, $urgentes),
            'tipos' => $this->tipos->listado(),
        ]);
    }

    /**
     * El guardia registra incidencias y ve las suyas de la última semana
     */
    public function misIncidencias(Request $request): Response
    {
        $guardia = $this->guardia($request);

        return Inertia::render('vigilancia/mis-incidencias', [
            'guardia' => $guardia->nombre,
            'incidencias' => $this->incidencias->deGuardia($guardia),
            'tipos' => $this->tipos->listado(soloActivos: true),
        ]);
    }

    /**
     * Registra la incidencia del guardia; la urgente se avisa por correo
     */
    public function store(RegistrarIncidenciaRequest $request): RedirectResponse
    {
        $guardia = $this->guardia($request);

        /** @var array{tipo_incidencia_id: int|string, descripcion: string, ocurrida_en: string, urgente?: bool|int|string|null} $datos */
        $datos = $request->safe()->except('foto');

        /** @var UploadedFile|null $foto */
        $foto = $request->file('foto');

        $resultado = $this->incidencias->registrar($this->usuario($request), $guardia, $datos, $foto);

        Inertia::flash('toast', match ($resultado['aviso_enviado']) {
            null => ['type' => 'success', 'message' => 'Incidencia registrada.'],
            true => ['type' => 'success', 'message' => 'Incidencia registrada. Se avisó al presidente por correo.'],
            false => ['type' => 'warning', 'message' => 'Incidencia registrada, pero no se pudo enviar el aviso por correo. Avisa al presidente por teléfono.'],
        });

        return back();
    }

    /**
     * Muestra la foto de la incidencia a quien ve las incidencias o al guardia que la registró
     */
    public function foto(Request $request, Incidencia $incidencia, PermisoService $permisos): StreamedResponse
    {
        $usuario = $this->usuario($request);

        abort_unless(
            $permisos->puede($usuario, 'incidencias', 'ver') || $this->turnos->guardiaDe($usuario)?->id === $incidencia->guardia_id,
            403,
            'Acceso denegado',
        );

        $ruta = $this->incidencias->foto($incidencia);

        abort_if($ruta === null, 404);

        return Storage::disk(IncidenciaService::DISCO)->response($ruta, "incidencia-{$incidencia->id}.".pathinfo($ruta, PATHINFO_EXTENSION));
    }

    private function guardia(Request $request): Guardia
    {
        $guardia = $this->turnos->guardiaDe($this->usuario($request));

        abort_if($guardia === null, 403, 'Esta cuenta no está vinculada a un guardia activo');

        return $guardia;
    }

    private function usuario(Request $request): User
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return $usuario;
    }
}
