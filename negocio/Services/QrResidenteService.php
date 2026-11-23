<?php

namespace Patronato\Negocio\Services;

use Illuminate\Support\Str;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\QrResidenteRepositorio;
use Patronato\Persistencia\Repositorios\ResidenteRepositorio;
use Patronato\Persistencia\Transaccion;
use SensitiveParameter;

class QrResidenteService
{
    public function __construct(
        private ResidenteRepositorio $residentes,
        private QrResidenteRepositorio $codigos,
        private PeriodoService $periodos,
        private Transaccion $transaccion,
    ) {}

    public function emitir(User $usuario, bool $regenerar = false): string
    {
        $residencialId = $usuario->residencial_id;
        abort_if($residencialId === null || ! $usuario->activo || $usuario->motivoSinAcceso() !== null, 403);

        return $this->transaccion->ejecutar(function () use ($usuario, $residencialId, $regenerar): string {
            // Se bloquea al residente incluso cuando todavía no tiene un QR.
            $residente = $this->residentes->bloquearActivoDeUsuario($usuario->id, $residencialId);
            abort_if($residente === null, 403, 'Tu cuenta no está vinculada a un residente activo.');
            abort_unless(
                $this->codigos->tieneCarnetVigente($residente, $this->periodos->hoy()->toDateString()),
                403,
                'Necesitas un carnet activo y vigente.',
            );

            if ($regenerar) {
                $this->codigos->invalidarDe($residente);
            } else {
                $actual = $this->codigos->activoDe($residente);

                if ($actual !== null) {
                    return $actual->token;
                }
            }

            return $this->codigos->emitir($residente, (string) Str::uuid())->token;
        });
    }

    /**
     * El consumidor debe pasar el residencial de su propio contexto autorizado.
     */
    public function esValido(int $residencialId, #[SensitiveParameter] string $token): bool
    {
        return Str::isUuid($token, 4)
            && $this->codigos->esValido($residencialId, strtolower($token), $this->periodos->hoy()->toDateString());
    }
}
