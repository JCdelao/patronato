<?php

namespace Patronato\Negocio\Services;

use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\ConfiguracionRepositorio;
use Patronato\Persistencia\Repositorios\ResidencialRepositorio;
use Patronato\Persistencia\Transaccion;

class ReglaAccesoPagoService
{
    public const AL_DIA = 'al_dia';

    public const DENEGADO = 'denegado';

    public const REVISAR = 'revisar';

    public function __construct(
        private ConfiguracionRepositorio $configuracion,
        private ResidencialRepositorio $residenciales,
        private Transaccion $transaccion,
        private PermisoService $permisos,
        private AuditoriaService $auditoria,
        private MoraService $mora,
    ) {}

    public function puedeConfigurar(User $usuario): bool
    {
        return $usuario->residencial_id !== null && $usuario->motivoSinAcceso() === null
            && $usuario->rol?->codigo === Rol::PRESIDENTE && $this->permisos->puede($usuario, 'mora', 'registrar');
    }

    /** @return array{acceso_depende_pago: bool, meses_tolerancia_acceso: int, pendiente_cuenta_al_dia: bool} */
    public function regla(int $residencialId): array
    {
        $valores = $this->configuracion->valores($residencialId, ['acceso_depende_pago', 'meses_tolerancia_acceso', 'pendiente_cuenta_al_dia']);

        return [
            'acceso_depende_pago' => (bool) ($valores['acceso_depende_pago'] ?? false),
            'meses_tolerancia_acceso' => (int) ($valores['meses_tolerancia_acceso'] ?? 0),
            'pendiente_cuenta_al_dia' => (bool) ($valores['pendiente_cuenta_al_dia'] ?? false),
        ];
    }

    /** @param array{acceso_depende_pago: bool, meses_tolerancia_acceso: int, pendiente_cuenta_al_dia: bool} $regla */
    public function guardar(User $usuario, array $regla): void
    {
        abort_unless($this->puedeConfigurar($usuario), 403);
        $residencialId = (int) $usuario->residencial_id;
        $this->transaccion->ejecutar(function () use ($usuario, $residencialId, $regla): void {
            $residencial = $this->residenciales->bloquear($residencialId);
            $anterior = $this->regla($residencialId);
            if ($anterior === $regla) {
                return;
            }

            $this->configuracion->guardar($residencialId, $regla);
            $this->auditoria->registrar($usuario, 'seguridad', 'regla_acceso_pago_cambiada', $residencial, [
                'valor_anterior' => $anterior,
                'valor_nuevo' => $regla,
            ]);
        });
    }

    /** @param list<int> $viviendas */
    public function evaluar(int $residencialId, array $viviendas): ?string
    {
        $regla = $this->regla($residencialId);
        if (! $regla['acceso_depende_pago']) {
            return null;
        }
        if (count($viviendas) !== 1) {
            return self::REVISAR;
        }

        $meses = $this->mora->mesesParaAcceso($residencialId, $viviendas[0], $regla['pendiente_cuenta_al_dia']);

        return match (true) {
            $meses === null => self::REVISAR,
            $meses > $regla['meses_tolerancia_acceso'] => self::DENEGADO,
            default => self::AL_DIA,
        };
    }
}
