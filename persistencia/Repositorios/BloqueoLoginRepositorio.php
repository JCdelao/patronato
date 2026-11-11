<?php

namespace Patronato\Persistencia\Repositorios;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Intentos fallidos y bloqueos de inicio de sesión guardados en caché
 */
class BloqueoLoginRepositorio
{
    /**
     * Momento, como marca de tiempo, en que termina el bloqueo
     */
    public function bloqueadoHasta(string $clave): ?int
    {
        $hasta = Cache::get("login.hasta.{$clave}");

        return is_numeric($hasta) ? (int) $hasta : null;
    }

    /**
     * Suma un intento fallido y devuelve el total
     */
    public function sumarIntento(string $clave): int
    {
        Cache::add("login.intentos.{$clave}", 0, now()->addDay());

        return (int) Cache::increment("login.intentos.{$clave}");
    }

    public function bloquear(string $clave, CarbonInterface $hasta): void
    {
        Cache::put("login.hasta.{$clave}", $hasta->getTimestamp(), $hasta);
        Cache::forget("login.intentos.{$clave}");
    }

    public function limpiar(string $clave): void
    {
        Cache::forget("login.intentos.{$clave}");
        Cache::forget("login.hasta.{$clave}");
    }
}
