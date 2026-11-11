<?php

namespace Patronato\Negocio\Services;

use Patronato\Persistencia\Repositorios\ConfiguracionRepositorio;

class ConfiguracionService
{
    private const SEGURIDAD = [
        'max_intentos_login' => 5,
        'minutos_bloqueo_login' => 15,
        'minutos_inactividad_sesion' => 30,
    ];

    public function __construct(private ConfiguracionRepositorio $configuracion) {}

    /**
     * Parámetros de acceso de un residencial
     *
     * @return array{max_intentos_login: int, minutos_bloqueo_login: int, minutos_inactividad_sesion: int}
     */
    public function seguridad(?int $residencialId): array
    {
        $fila = $residencialId === null ? null : $this->configuracion->valores($residencialId, array_keys(self::SEGURIDAD));

        return [
            'max_intentos_login' => (int) ($fila['max_intentos_login'] ?? self::SEGURIDAD['max_intentos_login']),
            'minutos_bloqueo_login' => (int) ($fila['minutos_bloqueo_login'] ?? self::SEGURIDAD['minutos_bloqueo_login']),
            'minutos_inactividad_sesion' => (int) ($fila['minutos_inactividad_sesion'] ?? self::SEGURIDAD['minutos_inactividad_sesion']),
        ];
    }

    /**
     * Indica si el residencial acepta pagos que no cubren el mes completo
     *
     * Sin configuración guardada vale falso, igual que el valor predeterminado de la tabla.
     */
    public function permiteAbonoParcial(int $residencialId): bool
    {
        return (bool) $this->configuracion->valor($residencialId, 'permite_abono_parcial');
    }

    /**
     * Crea la configuración inicial de un residencial
     */
    public function crear(int $residencialId): void
    {
        $this->configuracion->crear($residencialId);
    }

    /**
     * @param  array{max_intentos_login: int|string, minutos_bloqueo_login: int|string, minutos_inactividad_sesion: int|string}  $datos
     */
    public function actualizarSeguridad(int $residencialId, array $datos): void
    {
        $this->configuracion->guardar($residencialId, [
            'max_intentos_login' => (int) $datos['max_intentos_login'],
            'minutos_bloqueo_login' => (int) $datos['minutos_bloqueo_login'],
            'minutos_inactividad_sesion' => (int) $datos['minutos_inactividad_sesion'],
        ]);
    }
}
