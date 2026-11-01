<?php

namespace Patronato\Modules\Identidad\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BloqueoLoginService
{
    /**
     * Minutos que le faltan al bloqueo de la cuenta, o null si no está bloqueada (HU-04).
     */
    public function minutosRestantes(string $correo): ?int
    {
        /** @var int|null $hasta */
        $hasta = Cache::get($this->clave('hasta', $correo));

        if ($hasta === null || $hasta <= now()->getTimestamp()) {
            return null;
        }

        return (int) ceil(($hasta - now()->getTimestamp()) / 60);
    }

    /**
     * Cuenta un intento fallido; se cuenta aunque el correo no exista para no revelar qué cuentas hay.
     */
    public function registrarFallo(string $correo, int $maxIntentos, int $minutosBloqueo): void
    {
        $intentos = $this->clave('intentos', $correo);

        Cache::add($intentos, 0, now()->addDay());

        if ((int) Cache::increment($intentos) >= $maxIntentos) {
            Cache::put($this->clave('hasta', $correo), now()->addMinutes($minutosBloqueo)->getTimestamp(), now()->addMinutes($minutosBloqueo));
            Cache::forget($intentos);
        }
    }

    public function limpiar(string $correo): void
    {
        Cache::forget($this->clave('intentos', $correo));
        Cache::forget($this->clave('hasta', $correo));
    }

    private function clave(string $tipo, string $correo): string
    {
        return "login.{$tipo}.".sha1(Str::lower($correo));
    }
}
