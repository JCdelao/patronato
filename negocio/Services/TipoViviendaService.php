<?php

namespace Patronato\Negocio\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Patronato\Persistencia\Models\ConceptoCobro;
use Patronato\Persistencia\Models\Tarifa;
use Patronato\Persistencia\Models\TipoVivienda;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\ConceptoRepositorio;
use Patronato\Persistencia\Repositorios\TipoViviendaRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Tipos de vivienda de cada residencial y su cuota mensual
 */
class TipoViviendaService
{
    /**
     * Tipos con los que empieza cada residencial nuevo
     */
    public const PREDETERMINADOS = ['Casa', 'Apartamento'];

    public function __construct(
        private TipoViviendaRepositorio $tipos,
        private ConceptoRepositorio $conceptos,
        private Transaccion $transaccion,
        private TarifaService $tarifas,
        private PeriodoService $periodos,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Tipos del residencial con la marca de si alguna vivienda los usa
     *
     * @return Collection<int, TipoVivienda>
     */
    public function catalogo(): Collection
    {
        return $this->tipos->catalogo();
    }

    /**
     * Tipos que se pueden asignar a una vivienda
     *
     * @return Collection<int, TipoVivienda>
     */
    public function activos(): Collection
    {
        return $this->tipos->activos();
    }

    /**
     * Concepto donde se guarda la cuota mensual de cada tipo; lo crea la primera vez
     */
    private function conceptoCuota(int $residencialId): ConceptoCobro
    {
        return $this->conceptos->cuotaMensual($residencialId, TarifaService::CONCEPTO_CUOTA);
    }

    /**
     * Cuota mensual de cada tipo, del monto más antiguo al más reciente
     *
     * @param  array<int, int>  $tipoIds
     * @return array<int, list<Tarifa>>
     */
    public function cuotas(int $residencialId, array $tipoIds): array
    {
        $concepto = $this->conceptos->cuotaMensualExistente($residencialId);

        return $concepto === null ? [] : $this->tarifas->montosPorTipo($concepto, $tipoIds);
    }

    /**
     * Crea los tipos iniciales de un residencial nuevo, sin cuota
     */
    public function crearPredeterminados(int $residencialId): void
    {
        foreach (self::PREDETERMINADOS as $nombre) {
            $this->tipos->crear(['residencial_id' => $residencialId, 'nombre' => $nombre]);
        }
    }

    /**
     * Motivo por el que no se puede usar el nombre, o null si está libre
     */
    public function motivoNombreNoDisponible(int $residencialId, string $nombre, ?int $tipoId): ?string
    {
        return $this->tipos->existeNombre($residencialId, trim($nombre), $tipoId)
            ? 'Ya existe un tipo de vivienda con ese nombre.'
            : null;
    }

    /**
     * Registra un tipo de vivienda con su cuota mensual
     *
     * @param  array{nombre: string, monto: float|int|string, vigente_desde: string}  $datos
     *
     * @throws ValidationException
     */
    public function registrar(User $usuario, array $datos): TipoVivienda
    {
        $desde = $this->periodos->desdeMes($datos['vigente_desde']);
        $residencialId = (int) $usuario->residencial_id;

        return $this->transaccion->ejecutar(function () use ($usuario, $datos, $desde, $residencialId) {
            $tipo = $this->tipos->crear(['residencial_id' => $residencialId, 'nombre' => trim($datos['nombre'])]);

            $this->tarifas->programarMonto($this->conceptoCuota($residencialId), $this->monto($datos['monto']), $desde, $usuario->id, $tipo->id);

            $this->auditoria->registrar($usuario, 'finanzas', 'tipo_vivienda_creado', $tipo, [
                'valor_nuevo' => $this->resumen($tipo),
            ]);

            return $tipo;
        });
    }

    /**
     * Actualiza un tipo; una cuota distinta crea una vigencia nueva
     *
     * @param  array{nombre: string, monto: float|int|string, vigente_desde: string}  $datos
     *
     * @throws ValidationException
     */
    public function actualizar(User $usuario, TipoVivienda $tipo, array $datos): void
    {
        $desde = $this->periodos->desdeMes($datos['vigente_desde']);

        $this->transaccion->ejecutar(function () use ($usuario, $tipo, $datos, $desde) {
            $anterior = $this->resumen($tipo);
            $monto = $this->monto($datos['monto']);

            $this->tipos->actualizar($tipo, ['nombre' => trim($datos['nombre'])]);

            if ($monto !== $anterior['cuota']) {
                $this->tarifas->programarMonto($this->conceptoCuota($tipo->residencial_id), $monto, $desde, $usuario->id, $tipo->id);
            }

            $nuevo = $this->resumen($tipo);

            if ($anterior !== $nuevo) {
                $this->auditoria->registrar($usuario, 'finanzas', 'tipo_vivienda_editado', $tipo, [
                    'valor_anterior' => array_diff_assoc($anterior, $nuevo),
                    'valor_nuevo' => array_diff_assoc($nuevo, $anterior),
                ]);
            }
        });
    }

    /**
     * Desactiva un tipo para que no se asigne a viviendas nuevas
     */
    public function desactivar(User $usuario, TipoVivienda $tipo): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $tipo) {
            $this->tipos->actualizar($tipo, ['activo' => false]);

            $this->auditoria->registrar($usuario, 'finanzas', 'tipo_vivienda_desactivado', $tipo);
        });
    }

    /**
     * Reactiva un tipo
     */
    public function reactivar(User $usuario, TipoVivienda $tipo): void
    {
        $this->transaccion->ejecutar(function () use ($usuario, $tipo) {
            $this->tipos->actualizar($tipo, ['activo' => true]);

            $this->auditoria->registrar($usuario, 'finanzas', 'tipo_vivienda_reactivado', $tipo);
        });
    }

    /**
     * Borra un tipo que ninguna vivienda usa
     *
     * @throws ValidationException
     */
    public function eliminar(User $usuario, TipoVivienda $tipo): void
    {
        if ($this->tipos->tieneViviendas($tipo)) {
            throw ValidationException::withMessages([
                'tipo' => 'Hay viviendas de este tipo: solo se puede desactivar.',
            ]);
        }

        $this->transaccion->ejecutar(function () use ($usuario, $tipo) {
            $anterior = $this->resumen($tipo);

            $this->tarifas->eliminarDeTipo($tipo->id);
            $this->tipos->eliminar($tipo);

            $this->auditoria->registrar($usuario, 'finanzas', 'tipo_vivienda_eliminado', $tipo, [
                'valor_anterior' => $anterior,
            ]);
        });
    }

    private function monto(float|int|string $monto): string
    {
        return number_format((float) $monto, 2, '.', '');
    }

    /**
     * @return array{nombre: string, cuota: string|null, vigente_desde: string|null}
     */
    private function resumen(TipoVivienda $tipo): array
    {
        $ultima = $this->tarifas->ultimoMonto($this->conceptoCuota($tipo->residencial_id), $tipo->id);

        return [
            'nombre' => $tipo->nombre,
            'cuota' => $ultima?->monto,
            'vigente_desde' => $ultima?->vigente_desde->toDateString(),
        ];
    }
}
