<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\Preautorizacion;
use Patronato\Persistencia\Models\Residente;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\ViviendaResidente;
use Patronato\Persistencia\Repositorios\MedioEntradaRepositorio;
use Patronato\Persistencia\Repositorios\PreautorizacionRepositorio;
use Patronato\Persistencia\Repositorios\ResidenteRepositorio;
use Patronato\Persistencia\Repositorios\VinculoRepositorio;
use Patronato\Persistencia\Repositorios\ViviendaRepositorio;
use Patronato\Persistencia\Transaccion;

class PreautorizacionService
{
    private const ZONA = 'America/Tegucigalpa';

    public function __construct(
        private ResidenteRepositorio $residentes,
        private VinculoRepositorio $vinculos,
        private ViviendaRepositorio $viviendas,
        private MedioEntradaRepositorio $medios,
        private PreautorizacionRepositorio $preautorizaciones,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    public function residente(User $usuario): Residente
    {
        $residente = $this->residentes->activoDeUsuario($usuario->id);
        abort_if($usuario->motivoSinAcceso() !== null || $residente === null
            || $residente->residencial_id !== $usuario->residencial_id, 403, 'Tu cuenta no está vinculada a un residente activo.');

        return $residente;
    }

    /** @return list<array{id: int, codigo: string}> */
    public function viviendas(Residente $residente): array
    {
        return array_values($this->vinculos->vigentesDe($residente->id)->load('vivienda')
            ->filter(fn (ViviendaResidente $vinculo): bool => $vinculo->vivienda->activa)
            ->map(fn (ViviendaResidente $vinculo): array => ['id' => $vinculo->vivienda_id, 'codigo' => $vinculo->vivienda->codigo])
            ->all());
    }

    public function crear(User $usuario, int $viviendaId, int $medioId, string $nombre, string $inicio, string $fin): bool
    {
        $desde = CarbonImmutable::parse($inicio, self::ZONA)->utc();
        $hasta = CarbonImmutable::parse($fin, self::ZONA)->utc();
        if ($hasta->lessThanOrEqualTo($desde)) {
            throw ValidationException::withMessages(['fin' => 'El fin debe ser posterior al inicio.']);
        }

        return $this->transaccion->ejecutar(function () use ($usuario, $viviendaId, $medioId, $nombre, $desde, $hasta): bool {
            $residente = $this->residente($usuario);
            $residencialId = $residente->residencial_id;
            // Las bajas actualizan esta misma fila. El bloqueo también serializa altas idénticas.
            $vinculo = $this->vinculos->bloquearVigente($residencialId, $residente->id, $viviendaId);
            if ($vinculo === null || ! $vinculo->vivienda->activa) {
                throw ValidationException::withMessages(['vivienda_id' => 'Elige una vivienda activa donde tengas un vínculo vigente.']);
            }
            abort_unless($this->residente($usuario)->id === $residente->id, 403);
            if ($this->medios->bloquearActivo($residencialId, $medioId) === null) {
                throw ValidationException::withMessages(['medio_entrada_id' => 'Elige un medio de entrada activo de tu residencial.']);
            }

            $preautorizacion = $this->preautorizaciones->crearSiNueva([
                'residencial_id' => $residencialId,
                'residente_id' => $residente->id,
                'vivienda_id' => $viviendaId,
                'medio_entrada_id' => $medioId,
                'nombre_visitante' => trim($nombre),
                'inicio' => $desde,
                'fin' => $hasta,
            ]);
            if ($preautorizacion === null) {
                return false;
            }

            $this->auditoria->registrar($usuario, 'vigilancia', 'preautorizacion_creada', $preautorizacion, [
                'valor_nuevo' => ['vivienda_id' => $viviendaId, 'medio_entrada_id' => $medioId,
                    'inicio' => $desde->toIso8601String(), 'fin' => $hasta->toIso8601String()],
            ]);

            return true;
        });
    }

    /** @return list<array{id: int, visitante: string, medio: string|null, inicio: string, fin: string, estado: string, vinculo_vigente: bool}> */
    public function delDia(Guardia $guardia, int $viviendaId, ?string $fecha): array
    {
        $vivienda = $this->viviendas->buscar($viviendaId);
        abort_if($vivienda === null || $vivienda->residencial_id !== $guardia->residencial_id, 404);
        $ahora = CarbonImmutable::now(self::ZONA);
        $dia = $fecha === null ? $ahora->startOfDay() : CarbonImmutable::parse($fecha, self::ZONA)->startOfDay();

        return array_values($this->preautorizaciones->delDia($guardia->residencial_id, $viviendaId, $dia->utc(), $dia->addDay()->utc())
            ->map(fn (Preautorizacion $visita): array => [
                'id' => $visita->id,
                'visitante' => $visita->nombre_visitante,
                'medio' => $visita->medio?->nombre,
                'inicio' => $visita->inicio->toIso8601String(),
                'fin' => $visita->fin->toIso8601String(),
                'estado' => match (true) {
                    $visita->fin->lessThanOrEqualTo($ahora) => 'Vencida',
                    $visita->inicio->isAfter($ahora) => 'Futura',
                    ! $visita->vinculo_vigente => 'Sin autorización vigente',
                    default => 'Vigente',
                },
                'vinculo_vigente' => $visita->vinculo_vigente === true,
            ])->all());
    }
}
