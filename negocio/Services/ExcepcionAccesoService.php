<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\ExcepcionAccesoRepositorio;
use Patronato\Persistencia\Repositorios\ViviendaRepositorio;
use Patronato\Persistencia\Transaccion;

class ExcepcionAccesoService
{
    public function __construct(
        private ReglaAccesoPagoService $regla,
        private ViviendaRepositorio $viviendas,
        private ExcepcionAccesoRepositorio $excepciones,
        private AuditoriaService $auditoria,
        private Transaccion $transaccion,
    ) {}

    public function conceder(User $usuario, int $viviendaId, string $motivo, string $fechaLocal): void
    {
        abort_unless($this->regla->puedeConfigurar($usuario), 403);
        $residencialId = (int) $usuario->residencial_id;
        $vence = CarbonImmutable::parse($fechaLocal, 'America/Tegucigalpa')->utc();

        $this->transaccion->ejecutar(function () use ($usuario, $residencialId, $viviendaId, $motivo, $vence): void {
            if ($this->viviendas->bloquearActivas($residencialId, [$viviendaId]) === []) {
                throw ValidationException::withMessages(['vivienda_id' => 'Selecciona una vivienda activa de tu residencial.']);
            }

            $ahora = CarbonImmutable::now('UTC');
            if ($vence <= $ahora) {
                throw ValidationException::withMessages(['vence_en' => 'El vencimiento debe ser posterior a la hora actual de Honduras.']);
            }
            if ($this->regla->evaluar($residencialId, [$viviendaId]) !== ReglaAccesoPagoService::DENEGADO) {
                throw ValidationException::withMessages(['vivienda_id' => 'La regla de acceso debe estar activa y denegar el ingreso a esta vivienda por mora.']);
            }
            if ($this->excepciones->vigente($residencialId, $viviendaId, $ahora)) {
                throw ValidationException::withMessages(['vivienda_id' => 'La vivienda ya tiene una excepción vigente.']);
            }

            $excepcion = $this->excepciones->registrar($residencialId, $viviendaId, $usuario->id, $motivo, $vence);
            $this->auditoria->registrar($usuario, 'seguridad', 'excepcion_acceso_concedida', $excepcion, [
                'valor_nuevo' => ['vivienda_id' => $viviendaId, 'vence_en' => $vence->toIso8601String(), 'autorizado_por' => $usuario->id],
            ]);
        });
    }

    /** @param list<int> $viviendas */
    public function evaluar(int $residencialId, array $viviendas): ?string
    {
        $resultado = $this->regla->evaluar($residencialId, $viviendas);

        if ($resultado === ReglaAccesoPagoService::DENEGADO && isset($viviendas[0])
            && $this->excepciones->vigente($residencialId, $viviendas[0], CarbonImmutable::now('UTC'))) {
            return ReglaAccesoPagoService::AL_DIA;
        }

        return $resultado;
    }
}
