<?php

namespace Patronato\Modules\Padron\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Patronato\Modules\Identidad\Services\AuditoriaService;
use Patronato\Modules\Padron\Models\Residente;
use Patronato\Modules\Padron\Models\ViviendaResidente;

class ResidenteService
{
    public function __construct(private AuditoriaService $auditoria) {}

    /**
     * Registra un residente en una vivienda
     *
     * @param  array{vivienda_id: int|string, tipo: string, nombres: string, apellidos: string, identidad?: string|null, telefono?: string|null, correo?: string|null}  $datos
     */
    public function registrar(User $usuario, array $datos, bool $esResponsable): Residente
    {
        return DB::transaction(function () use ($usuario, $datos, $esResponsable) {
            $residente = Residente::query()->create([
                'residencial_id' => $usuario->residencial_id,
                ...$this->datosPersonales($datos),
            ]);

            $this->vincular($residente, (int) $datos['vivienda_id'], $datos['tipo'], $esResponsable);

            $this->auditoria->registrar($usuario, 'padron', 'residente_registrado', $residente, [
                'valor_nuevo' => [...$this->datosPersonales($datos), 'vivienda_id' => (int) $datos['vivienda_id'], 'tipo' => $datos['tipo']],
            ]);

            return $residente;
        });
    }

    /**
     * Actualiza un residente y su vínculo con una de sus viviendas
     *
     * @param  array{vivienda_id: int|string, tipo: string, nombres: string, apellidos: string, identidad?: string|null, telefono?: string|null, correo?: string|null}  $datos
     *
     * @throws ValidationException
     */
    public function actualizar(User $usuario, Residente $residente, ?int $viviendaOrigenId, array $datos, bool $esResponsable): void
    {
        $vinculo = $residente->vinculosVigentes()
            ->when($viviendaOrigenId !== null, fn ($query) => $query->where('vivienda_id', $viviendaOrigenId))
            ->orderBy('id')
            ->first();

        if ($vinculo === null) {
            throw ValidationException::withMessages(['vivienda_id' => 'El residente ya no vive en esa vivienda.']);
        }

        $viviendaId = (int) $datos['vivienda_id'];

        if ($vinculo->vivienda_id !== $viviendaId && $this->viveEn($residente, $viviendaId)) {
            throw ValidationException::withMessages(['vivienda_id' => 'El residente ya vive en esa vivienda.']);
        }

        DB::transaction(function () use ($usuario, $residente, $vinculo, $viviendaId, $datos, $esResponsable) {
            $anterior = [...$this->datosPersonales($residente->toArray()), 'vivienda_id' => $vinculo->vivienda_id, 'tipo' => $vinculo->tipo];

            $residente->update($this->datosPersonales($datos));

            if ($vinculo->vivienda_id !== $viviendaId) {
                $this->cerrar($vinculo);
                $this->vincular($residente, $viviendaId, $datos['tipo'], $esResponsable);
            } else {
                if ($esResponsable) {
                    $this->quitarResponsable($viviendaId);
                }

                $vinculo->update(['tipo' => $datos['tipo'], 'es_responsable_pago' => $esResponsable]);

                $this->asegurarResponsable($viviendaId);
            }

            $nuevo = [...$this->datosPersonales($datos), 'vivienda_id' => $viviendaId, 'tipo' => $datos['tipo']];

            if ($anterior !== $nuevo) {
                $this->auditoria->registrar($usuario, 'padron', 'residente_editado', $residente, [
                    'valor_anterior' => array_diff_assoc($anterior, $nuevo),
                    'valor_nuevo' => array_diff_assoc($nuevo, $anterior),
                ]);
            }
        });
    }

    /**
     * Agrega una vivienda a un residente y lo reactiva si estaba inactivo
     *
     * @param  array{vivienda_id: int|string, tipo: string}  $datos
     *
     * @throws ValidationException
     */
    public function agregarVivienda(User $usuario, Residente $residente, array $datos, bool $esResponsable): void
    {
        $viviendaId = (int) $datos['vivienda_id'];

        if ($this->viveEn($residente, $viviendaId)) {
            throw ValidationException::withMessages(['vivienda_id' => 'El residente ya vive en esa vivienda.']);
        }

        DB::transaction(function () use ($usuario, $residente, $viviendaId, $datos, $esResponsable) {
            $reactivado = ! $residente->activo;

            if ($reactivado) {
                $residente->update(['activo' => true]);
            }

            $this->vincular($residente, $viviendaId, $datos['tipo'], $esResponsable);

            $this->auditoria->registrar($usuario, 'padron', $reactivado ? 'residente_reactivado' : 'residente_vinculado', $residente, [
                'valor_nuevo' => ['vivienda_id' => $viviendaId, 'tipo' => $datos['tipo']],
            ]);
        });
    }

    /**
     * Saca a un residente de una vivienda, o de todas, y lo deja inactivo si ya no vive en ninguna
     */
    public function darDeBaja(User $usuario, Residente $residente, ?int $viviendaId = null): void
    {
        DB::transaction(function () use ($usuario, $residente, $viviendaId) {
            $vinculos = $residente->vinculosVigentes()
                ->when($viviendaId !== null, fn ($query) => $query->where('vivienda_id', $viviendaId))
                ->get();

            $vinculos->each(fn (ViviendaResidente $vinculo) => $this->cerrar($vinculo));

            $sigue = $residente->vinculosVigentes()->exists();

            if (! $sigue) {
                $residente->update(['activo' => false]);
            }

            $this->auditoria->registrar($usuario, 'padron', $sigue ? 'residente_retirado_de_vivienda' : 'residente_dado_de_baja', $residente, [
                'valor_anterior' => ['viviendas' => $vinculos->pluck('vivienda_id')->all()],
            ]);
        });
    }

    /**
     * Asocia o libera la cuenta de un residente
     */
    public function vincularCuenta(int $usuarioId, ?int $residenteId): void
    {
        Residente::query()->where('user_id', $usuarioId)->update(['user_id' => null]);

        if ($residenteId !== null) {
            Residente::query()->whereKey($residenteId)->update(['user_id' => $usuarioId]);
        }
    }

    /**
     * Residentes activos que pueden recibir una cuenta
     *
     * @return array<int, array{id: int, nombre: string, vivienda: string|null, user_id: int|null}>
     */
    public function paraCuentas(): array
    {
        return Residente::query()
            ->where('activo', true)
            ->with('vinculosVigentes.vivienda')
            ->orderBy('nombres')
            ->get()
            ->map(fn (Residente $residente) => [
                'id' => $residente->id,
                'nombre' => "{$residente->nombres} {$residente->apellidos}",
                'vivienda' => $residente->vinculosVigentes
                    ->map(fn (ViviendaResidente $vinculo) => $vinculo->vivienda->codigo)
                    ->sort()
                    ->implode(', ') ?: null,
                'user_id' => $residente->user_id,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $datos
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

    private function viveEn(Residente $residente, int $viviendaId): bool
    {
        return $residente->vinculosVigentes()->where('vivienda_id', $viviendaId)->exists();
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
     * Asegura un responsable de pago en cada vivienda habitada
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
