<?php

namespace Patronato\Negocio\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Models\Vivienda;
use Patronato\Persistencia\Repositorios\ViviendaRepositorio;
use Patronato\Persistencia\Transaccion;

class ViviendaService
{
    public function __construct(
        private ViviendaRepositorio $viviendas,
        private Transaccion $transaccion,
        private TarifaService $tarifas,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Viviendas activas o inactivas del padrón con sus residentes
     *
     * @return LengthAwarePaginator<int, Vivienda>
     */
    public function padron(bool $activas, string $buscar): LengthAwarePaginator
    {
        return $this->viviendas->paginarPadron($activas, $buscar, 24);
    }

    /**
     * Id y código de las viviendas activas
     *
     * @return Collection<int, Vivienda>
     */
    public function activas(): Collection
    {
        return $this->viviendas->activas();
    }

    /**
     * Registra una vivienda
     *
     * @param  array{codigo: string, tipo_vivienda_id: int|string, vivienda_padre_id?: int|string|null, edificio_torre?: string|null, calle_bloque?: string|null, numero: string, estado_ocupacion: string}  $datos
     */
    public function registrar(User $usuario, array $datos): Vivienda
    {
        $this->validarPadre(null, $datos);

        return $this->transaccion->ejecutar(function () use ($usuario, $datos) {
            $vivienda = $this->viviendas->crear([
                'residencial_id' => $usuario->residencial_id,
                ...$this->datosVivienda($datos),
            ]);

            $this->auditoria->registrar($usuario, 'padron', 'vivienda_registrada', $vivienda, [
                'valor_nuevo' => $this->resumen($vivienda),
            ]);

            return $vivienda;
        });
    }

    /**
     * Actualiza los datos de una vivienda
     *
     * @param  array{codigo: string, tipo_vivienda_id: int|string, vivienda_padre_id?: int|string|null, edificio_torre?: string|null, calle_bloque?: string|null, numero: string, estado_ocupacion: string}  $datos
     */
    public function actualizar(User $usuario, Vivienda $vivienda, array $datos): void
    {
        $this->validarPadre($vivienda, $datos);

        $this->transaccion->ejecutar(function () use ($usuario, $vivienda, $datos) {
            $anterior = $this->resumen($vivienda);

            $this->viviendas->actualizar($vivienda, $this->datosVivienda($datos));

            $nuevo = $this->resumen($vivienda);

            if ($anterior !== $nuevo) {
                $this->auditoria->registrar($usuario, 'padron', 'vivienda_editada', $vivienda, [
                    'valor_anterior' => array_diff_assoc($anterior, $nuevo),
                    'valor_nuevo' => array_diff_assoc($nuevo, $anterior),
                ]);
            }
        });
    }

    /**
     * Reactiva una vivienda
     */
    public function reactivar(User $usuario, Vivienda $vivienda): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $vivienda) {
            $this->viviendas->actualizar($vivienda, ['activa' => true]);

            $this->auditoria->registrar($usuario, 'padron', 'vivienda_reactivada', $vivienda);
        });
    }

    /**
     * Borra una vivienda o la deja inactiva
     *
     * @throws ValidationException
     */
    public function eliminar(User $usuario, Vivienda $vivienda): bool
    {
        if ($this->viviendas->tieneResidentes($vivienda)) {
            throw ValidationException::withMessages([
                'vivienda' => 'La vivienda tiene residentes: dalos de baja antes de eliminarla.',
            ]);
        }

        $anterior = $this->resumen($vivienda);

        try {
            $this->transaccion->ejecutar(function () use ($usuario, $vivienda, $anterior) {
                $this->tarifas->eliminarDeVivienda($vivienda->id);
                $this->viviendas->eliminar($vivienda);

                $this->auditoria->registrar($usuario, 'padron', 'vivienda_eliminada', $vivienda, [
                    'valor_anterior' => $anterior,
                ]);
            });

            return true;
        } catch (QueryException) {
            // Si otra tabla la referencia, queda inactiva
            $this->transaccion->ejecutar(function () use ($usuario, $vivienda) {
                $this->viviendas->inactivar($vivienda);

                $this->auditoria->registrar($usuario, 'padron', 'vivienda_inactivada', $vivienda, [
                    'motivo' => 'La vivienda tiene historial y no se puede borrar',
                ]);
            });

            return false;
        }
    }

    /**
     * Viviendas activas que coinciden con la búsqueda, o la vivienda indicada
     *
     * Busca por código o por nombre del responsable de pago.
     *
     * @return array<int, array{id: int, codigo: string, responsable: string|null}>
     */
    public function opcionesActivas(string $buscar = '', ?int $viviendaId = null, int $limite = 10): array
    {
        return $this->viviendas->activasConResponsable($buscar, $viviendaId, $limite)
            ->map(function (Vivienda $vivienda): array {
                $residente = $vivienda->vinculosVigentes->first()?->residente;

                return [
                    'id' => $vivienda->id,
                    'codigo' => $vivienda->codigo,
                    'responsable' => $residente === null ? null : trim("{$residente->nombres} {$residente->apellidos}"),
                ];
            })
            ->all();
    }

    /**
     * @param  array{codigo: string, tipo_vivienda_id: int|string, vivienda_padre_id?: int|string|null, edificio_torre?: string|null, calle_bloque?: string|null, numero: string, estado_ocupacion: string}  $datos
     * @return array<string, int|string|null>
     */
    private function datosVivienda(array $datos): array
    {
        return [
            'codigo' => $datos['codigo'],
            'tipo_vivienda_id' => (int) $datos['tipo_vivienda_id'],
            'vivienda_padre_id' => empty($datos['vivienda_padre_id']) ? null : (int) $datos['vivienda_padre_id'],
            'edificio_torre' => $datos['edificio_torre'] ?? null,
            'calle_bloque' => $datos['calle_bloque'] ?? null,
            'numero' => $datos['numero'],
            'estado_ocupacion' => $datos['estado_ocupacion'],
        ];
    }

    /**
     * Revisa que la vivienda a la que pertenece sea válida
     *
     * Solo hay un nivel: un apartamento con habitaciones no pertenece a otra vivienda.
     *
     * @param  array{vivienda_padre_id?: int|string|null}  $datos
     *
     * @throws ValidationException
     */
    private function validarPadre(?Vivienda $vivienda, array $datos): void
    {
        $padreId = empty($datos['vivienda_padre_id']) ? null : (int) $datos['vivienda_padre_id'];

        if ($padreId === null) {
            return;
        }

        $padre = $this->viviendas->buscar($padreId);

        $motivo = match (true) {
            $padre === null => 'Esa vivienda no existe.',
            $vivienda !== null && $padre->id === $vivienda->id => 'Una vivienda no puede pertenecer a sí misma.',
            $padre->vivienda_padre_id !== null => "{$padre->codigo} ya pertenece a otra vivienda.",
            $vivienda !== null && $this->viviendas->tieneHijas($vivienda) => 'Otras viviendas pertenecen a esta: no puede pertenecer a otra.',
            default => null,
        };

        if ($motivo !== null) {
            throw ValidationException::withMessages(['vivienda_padre_id' => $motivo]);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function resumen(Vivienda $vivienda): array
    {
        $this->viviendas->cargarTipoYPadre($vivienda);

        return [
            'codigo' => $vivienda->codigo,
            'tipo' => $vivienda->tipoVivienda->nombre,
            'pertenece_a' => $vivienda->padre?->codigo,
            'edificio_torre' => $vivienda->edificio_torre,
            'calle_bloque' => $vivienda->calle_bloque,
            'numero' => $vivienda->numero,
            'estado_ocupacion' => $vivienda->estado_ocupacion,
        ];
    }
}
