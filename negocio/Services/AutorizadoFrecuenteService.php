<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\AutorizadoFrecuente;
use Patronato\Persistencia\Models\Guardia;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Repositorios\AutorizadoFrecuenteRepositorio;
use Patronato\Persistencia\Repositorios\ResidencialRepositorio;
use Patronato\Persistencia\Repositorios\ViviendaRepositorio;
use Patronato\Persistencia\Transaccion;

class AutorizadoFrecuenteService
{
    public function __construct(
        private AutorizadoFrecuenteRepositorio $autorizados,
        private ResidencialRepositorio $residenciales,
        private ViviendaRepositorio $viviendas,
        private PermisoService $permisos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /** @return list<array{id: int, codigo: string}> */
    public function viviendas(User $usuario): array
    {
        return array_values($this->viviendas->activasDe($this->residencialAutorizado($usuario))
            ->map(fn (Vivienda $vivienda) => ['id' => $vivienda->id, 'codigo' => $vivienda->codigo])->all());
    }

    /**
     * @param  list<int>  $dias
     * @param  list<int>  $viviendas
     */
    public function crear(User $usuario, string $nombre, string $tipo, array $dias, string $inicio, string $fin, array $viviendas): bool
    {
        $residencial = $this->residencialAutorizado($usuario);
        if ($inicio === $fin) {
            throw ValidationException::withMessages(['hora_fin' => 'La hora de fin debe ser distinta de la hora de inicio.']);
        }
        $inicio .= ':00';
        $fin .= ':00';
        $nombre = Str::squish($nombre);
        $tipo = Str::squish($tipo);
        $dias = array_unique($dias);
        sort($dias);
        $viviendas = array_unique($viviendas);
        sort($viviendas);

        return $this->transaccion->ejecutar(function () use ($usuario, $residencial, $nombre, $tipo, $dias, $inicio, $fin, $viviendas): bool {
            // La fila existe incluso antes de la primera autorización.
            abort_if($this->residenciales->bloquear($residencial)->estado !== Residencial::ACTIVO, 403);
            if ($this->viviendas->bloquearActivas($residencial, $viviendas) !== $viviendas) {
                throw ValidationException::withMessages(['viviendas' => 'Elige únicamente viviendas activas de tu residencial.']);
            }
            if ($this->autorizados->existeActivoIdentico($residencial, $nombre, $tipo, $dias, $inicio, $fin, $viviendas)) {
                return false;
            }

            $datos = ['nombre' => $nombre, 'tipo' => $tipo, 'dias_semana' => $dias, 'hora_inicio' => $inicio, 'hora_fin' => $fin];
            $autorizado = $this->autorizados->crearConViviendas($residencial, [
                ...$datos, 'activo' => true, 'created_by' => $usuario->id,
            ], $viviendas);
            $this->auditoria->registrar($usuario, 'vigilancia', 'autorizado_frecuente_creado', $autorizado, [
                'valor_nuevo' => [...$datos, 'viviendas' => $viviendas],
            ]);

            return true;
        });
    }

    /** @return list<array{id: int, nombre: string, tipo: string, dias: list<int>, inicio: string, fin: string, estado: string}> */
    public function deVivienda(Guardia $guardia, int $viviendaId): array
    {
        $vivienda = $this->viviendas->buscar($viviendaId);
        abort_if($vivienda === null || $vivienda->residencial_id !== $guardia->residencial_id, 404);
        $ahora = CarbonImmutable::now('America/Tegucigalpa');

        return array_values($this->autorizados->deVivienda($guardia->residencial_id, $viviendaId)
            ->map(fn (AutorizadoFrecuente $autorizado) => [
                'id' => $autorizado->id, 'nombre' => $autorizado->nombre, 'tipo' => $autorizado->tipo,
                'dias' => $autorizado->dias_semana, 'inicio' => substr($autorizado->hora_inicio, 0, 5),
                'fin' => substr($autorizado->hora_fin, 0, 5), 'estado' => $this->estado($autorizado, $ahora),
            ])->all());
    }

    private function estado(AutorizadoFrecuente $autorizado, CarbonImmutable $ahora): string
    {
        if (! $autorizado->activo) {
            return 'Autorización inactiva';
        }

        $hora = $ahora->format('H:i:s');
        $inicio = substr($autorizado->hora_inicio, 0, 8);
        $fin = substr($autorizado->hora_fin, 0, 8);
        $hoy = in_array($ahora->isoWeekday(), $autorizado->dias_semana, true);
        $ayer = in_array($ahora->subDay()->isoWeekday(), $autorizado->dias_semana, true);
        $vigente = $inicio <= $fin
            ? $hoy && $hora >= $inicio && $hora < $fin
            : ($hoy && $hora >= $inicio) || ($ayer && $hora < $fin);

        return $vigente ? 'Autorizado en este horario' : 'No autorizado en este horario';
    }

    private function residencialAutorizado(User $usuario): int
    {
        $residencial = $usuario->residencial_id;
        abort_if($residencial === null || $usuario->motivoSinAcceso() !== null
            || ! $this->permisos->puede($usuario, 'autorizados_frecuentes', 'registrar'), 403);

        return $residencial;
    }
}
