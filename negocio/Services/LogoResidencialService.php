<?php

namespace Patronato\Negocio\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Logo del residencial listo para insertarlo en un PDF
 */
class LogoResidencialService
{
    /**
     * El logo como data URI, para que dompdf no tenga que abrir archivos del disco; null si no hay
     */
    public function enLinea(?string $ruta): ?string
    {
        $disco = Storage::disk('public');

        if ($ruta === null || ! $disco->exists($ruta)) {
            return null;
        }

        $tipo = $disco->mimeType($ruta) ?: 'image/png';

        return "data:{$tipo};base64,".base64_encode((string) $disco->get($ruta));
    }
}
