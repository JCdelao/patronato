<?php

namespace Patronato\Presentacion\Controllers;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Patronato\Negocio\Services\QrResidenteService;
use Patronato\Persistencia\Models\User;

class PortalQrController
{
    public function __construct(private QrResidenteService $codigos) {}

    public function show(): InertiaResponse
    {
        return Inertia::render('portal/mi-qr');
    }

    public function emitir(Request $request): JsonResponse|Response
    {
        return $this->responder($request, false);
    }

    public function regenerar(Request $request): JsonResponse|Response
    {
        return $this->responder($request, true);
    }

    private function responder(Request $request, bool $regenerar): JsonResponse|Response
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 403);

        $token = $this->codigos->emitir($usuario, $regenerar);

        if ($request->prefers(['application/json', 'image/svg+xml']) === 'image/svg+xml') {
            $writer = new Writer(new ImageRenderer(new RendererStyle(256), new SvgImageBackEnd));

            return response($writer->writeString($token), 200, [
                'Content-Type' => 'image/svg+xml',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->json(['token' => $token])->header('Cache-Control', 'no-store');
    }
}
