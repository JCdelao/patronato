<?php

namespace Patronato\Negocio\Services;

use Illuminate\Support\Str;
use Patronato\Persistencia\Repositorios\BloqueoLoginRepositorio;

class BloqueoLoginService
{
    public function __construct(private BloqueoLoginRepositorio $bloqueos) {}

    /**
     * Minutos que faltan para desbloquear la cuenta
     */
    public function minutosRestantes(string $correo): ?int
    {
        $hasta = $this->bloqueos->bloqueadoHasta($this->clave($correo));

        if ($hasta === null || $hasta <= now()->getTimestamp()) {
            return null;
        }

        return (int) ceil(($hasta - now()->getTimestamp()) / 60);
    }

    /**
     * Cuenta un intento fallido
     */
    public function registrarFallo(string $correo, int $maxIntentos, int $minutosBloqueo): void
    {
        $clave = $this->clave($correo);

        if ($this->bloqueos->sumarIntento($clave) >= $maxIntentos) {
            $this->bloqueos->bloquear($clave, now()->addMinutes($minutosBloqueo));
        }
    }

    public function limpiar(string $correo): void
    {
        $this->bloqueos->limpiar($this->clave($correo));
    }

    private function clave(string $correo): string
    {
        return sha1(Str::lower($correo));
    }
}
