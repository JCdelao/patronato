<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\EscaneoQrService;
use Patronato\Persistencia\Models\User;

class EscaneoQrController extends Controller
{
    public function __construct(private EscaneoQrService $escaneos) {}

    public function index(Request $request): Response
    {
        $guardia = $this->escaneos->guardia($this->usuario($request));

        return Inertia::render('vigilancia/escanear-qr', ['historial' => $this->escaneos->historial($guardia)]);
    }

    public function store(Request $request): JsonResponse
    {
        $token = $request->input('codigo');
        $datos = $this->escaneos->escanear($this->usuario($request), is_string($token) && strlen($token) <= 100 ? $token : '');

        return response()->json($datos, 201, ['Cache-Control' => 'no-store']);
    }

    public function decidir(Request $request, int $escaneo): HttpResponse
    {
        $request->validate(['decision' => 'required|in:ingreso,no_ingreso']);
        $this->escaneos->decidir($this->usuario($request), $escaneo, $request->string('decision')->toString());

        return response()->noContent();
    }

    public function foto(Request $request, int $escaneo): HttpResponse
    {
        $ruta = $this->escaneos->foto($this->escaneos->guardia($this->usuario($request)), $escaneo);
        abort_if($ruta === null, 404);
        $disco = Storage::disk('local');
        $contenido = $disco->get($ruta);
        abort_if($contenido === null, 404);
        $tipo = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenido);
        abort_unless(in_array($tipo, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return response($contenido, 200, ['Content-Type' => $tipo, 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function usuario(Request $request): User
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);

        return $usuario;
    }
}
