<?php

namespace Patronato\Modules\Padron\Services;

use Illuminate\Support\Facades\DB;
use Patronato\Modules\Padron\Models\Residente;
use Patronato\Modules\Padron\Models\ViviendaResidente;

class ResidenteService
{
    /**
     * Registra un residente en una vivienda (HU-11).
     *
     * @param  array{vivienda_id: int|string, tipo: string, nombres: string, apellidos: string, identidad?: string|null, telefono?: string|null, correo?: string|null}  $datos
     */
    public function registrar(int $residencialId, array $datos, bool $esResponsable): Residente
    {
        return DB::transaction(function () use ($residencialId, $datos, $esResponsable) {
            $residente = Residente::query()->create([
                'residencial_id' => $residencialId,
                ...$this->datosPersonales($datos),
            ]);

            $this->vincular($residente, (int) $datos['vivienda_id'], $datos['tipo'], $esResponsable);

            return $residente;
        });
    }

    /**
     * Actualiza un residente; si cambia de vivienda se conserva el vínculo anterior como historial.
     *
     * @param  array{vivienda_id: int|string, tipo: string, nombres: string, apellidos: string, identidad?: string|null, telefono?: string|null, correo?: string|null}  $datos
     */
    public function actualizar(Residente $residente, array $datos, bool $esResponsable): void
    {
        DB::transaction(function () use ($residente, $datos, $esResponsable) {
            $residente->update($this->datosPersonales($datos));

            $viviendaId = (int) $datos['vivienda_id'];
            $vinculo = $residente->vinculoVigente;

            if ($vinculo === null || $vinculo->vivienda_id !== $viviendaId) {
                if ($vinculo !== null) {
                    $this->cerrar($vinculo);
                }

                $this->vincular($residente, $viviendaId, $datos['tipo'], $esResponsable);

                return;
            }

            if ($esResponsable) {
                $this->quitarResponsable($viviendaId);
            }

            $vinculo->update(['tipo' => $datos['tipo'], 'es_responsable_pago' => $esResponsable]);

            $this->asegurarResponsable($viviendaId);
        });
    }

    /**
     * Asocia una cuenta de usuario al residente indicado, o la libera si no se indica ninguno (HU-06).
     */
    public function vincularCuenta(int $usuarioId, ?int $residenteId): void
    {
        Residente::query()->where('user_id', $usuarioId)->update(['user_id' => null]);

        if ($residenteId !== null) {
            Residente::query()->whereKey($residenteId)->update(['user_id' => $usuarioId]);
        }
    }

    /**
     * Residentes activos que pueden recibir una cuenta, con la vivienda donde viven.
     *
     * @return array<int, array{id: int, nombre: string, vivienda: string|null, user_id: int|null}>
     */
    public function paraCuentas(): array
    {
        return Residente::query()
            ->where('activo', true)
            ->with('vinculoVigente.vivienda')
            ->orderBy('nombres')
            ->get()
            ->map(fn (Residente $residente) => [
                'id' => $residente->id,
                'nombre' => "{$residente->nombres} {$residente->apellidos}",
                'vivienda' => $residente->vinculoVigente?->vivienda->codigo,
                'user_id' => $residente->user_id,
            ])
            ->values()
            ->all();
    }

    /**
     * Da de baja a un residente conservando su historial (HU-11).
     */
    public function darDeBaja(Residente $residente): void
    {
        DB::transaction(function () use ($residente) {
            $residente->update(['activo' => false]);

            if ($residente->vinculoVigente !== null) {
                $this->cerrar($residente->vinculoVigente);
            }
        });
    }

    /**
     * @param  array{nombres: string, apellidos: string, identidad?: string|null, telefono?: string|null, correo?: string|null}  $datos
     * @return array<string, string|null>
     */
    private function datosPersonales(array $datos): array
    {
        return [
            'nombres' => $datos['nombres'],
            'apellidos' => $datos['apellidos'],
            'identidad' => $datos['identidad'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'correo' => $datos['correo'] ?? null,
        ];
    }

    private function vincular(Residente $residente, int $viviendaId, string $tipo, bool $esResponsable): void
    {
        if ($esResponsable) {
            $this->quitarResponsable($viviendaId);
        }

        ViviendaResidente::query()->create([
            'residencial_id' => $residente->residencial_id,
            'vivienda_id' => $viviendaId,
            'residente_id' => $residente->id,
            'tipo' => $tipo,
            'es_responsable_pago' => $esResponsable,
            'fecha_inicio' => now()->toDateString(),
        ]);

        $this->asegurarResponsable($viviendaId);
    }

    private function cerrar(ViviendaResidente $vinculo): void
    {
        $vinculo->update(['fecha_fin' => now()->toDateString(), 'es_responsable_pago' => false]);

        $this->asegurarResponsable($vinculo->vivienda_id);
    }

    private function quitarResponsable(int $viviendaId): void
    {
        ViviendaResidente::query()
            ->where('vivienda_id', $viviendaId)
            ->whereNull('fecha_fin')
            ->update(['es_responsable_pago' => false]);
    }

    /**
     * Mantiene un responsable de pago por vivienda habitada: si falta, lo es el residente más antiguo.
     */
    private function asegurarResponsable(int $viviendaId): void
    {
        $vinculos = ViviendaResidente::query()
            ->where('vivienda_id', $viviendaId)
            ->whereNull('fecha_fin');

        if ($vinculos->clone()->where('es_responsable_pago', true)->exists()) {
            return;
        }

        $vinculos->orderBy('id')->first()?->update(['es_responsable_pago' => true]);
    }
}
