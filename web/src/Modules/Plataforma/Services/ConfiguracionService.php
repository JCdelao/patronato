<?php

namespace Patronato\Modules\Plataforma\Services;

use Illuminate\Support\Facades\DB;

class ConfiguracionService
{
    private const SEGURIDAD = [
        'max_intentos_login' => 5,
        'minutos_bloqueo_login' => 15,
        'minutos_inactividad_sesion' => 30,
    ];

    /**
     * Parámetros de acceso de un residencial
     *
     * @return array{max_intentos_login: int, minutos_bloqueo_login: int, minutos_inactividad_sesion: int}
     */
    public function seguridad(?int $residencialId): array
    {
        $fila = $residencialId === null
            ? null
            : DB::table('configuracion_residencial')->where('residencial_id', $residencialId)->first(array_keys(self::SEGURIDAD));

        return [
            'max_intentos_login' => (int) ($fila->max_intentos_login ?? self::SEGURIDAD['max_intentos_login']),
            'minutos_bloqueo_login' => (int) ($fila->minutos_bloqueo_login ?? self::SEGURIDAD['minutos_bloqueo_login']),
            'minutos_inactividad_sesion' => (int) ($fila->minutos_inactividad_sesion ?? self::SEGURIDAD['minutos_inactividad_sesion']),
        ];
    }

    /**
     * Indica si el residencial acepta pagos que no cubren el mes completo
     *
     * Sin configuración guardada vale falso, igual que el valor predeterminado de la tabla.
     */
    public function permiteAbonoParcial(int $residencialId): bool
    {
        return (bool) DB::table('configuracion_residencial')
            ->where('residencial_id', $residencialId)
            ->value('permite_abono_parcial');
    }

    /**
     * @param  array{max_intentos_login: int|string, minutos_bloqueo_login: int|string, minutos_inactividad_sesion: int|string}  $datos
     */
    public function actualizarSeguridad(int $residencialId, array $datos): void
    {
        DB::table('configuracion_residencial')->updateOrInsert(
            ['residencial_id' => $residencialId],
            [
                'max_intentos_login' => (int) $datos['max_intentos_login'],
                'minutos_bloqueo_login' => (int) $datos['minutos_bloqueo_login'],
                'minutos_inactividad_sesion' => (int) $datos['minutos_inactividad_sesion'],
                'updated_at' => now(),
            ],
        );
    }
}
