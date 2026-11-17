<?php

namespace Patronato\Negocio\Services;

use Patronato\Persistencia\Models\CategoriaFinanciera;
use Patronato\Persistencia\Models\Presupuesto;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\CategoriaFinancieraRepositorio;
use Patronato\Persistencia\Repositorios\EgresoRepositorio;
use Patronato\Persistencia\Repositorios\PresupuestoRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Presupuesto anual de gasto por categoría y su comparación con lo ejecutado
 */
class PresupuestoService
{
    /**
     * Desde este porcentaje ejecutado la categoría se resalta
     */
    public const UMBRAL_ALERTA = 90;

    public const SIN_PRESUPUESTO = 'sin_presupuesto';

    public const NORMAL = 'normal';

    public const ALERTA = 'alerta';

    public const EXCEDIDO = 'excedido';

    public function __construct(
        private PresupuestoRepositorio $presupuestos,
        private CategoriaFinancieraRepositorio $categorias,
        private EgresoRepositorio $egresos,
        private PeriodoService $periodos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Años que se pueden consultar: desde el 2000 hasta el siguiente, para planificar
     *
     * @return array{minimo: int, maximo: int, actual: int}
     */
    public function anios(): array
    {
        $actual = $this->periodos->hoy()->year;

        return ['minimo' => 2000, 'maximo' => $actual + 1, 'actual' => $actual];
    }

    /**
     * Presupuestado, ejecutado y porcentaje de cada categoría de egreso en un año
     *
     * Aparecen las categorías activas y las inactivas que tienen presupuesto o gasto ese año.
     *
     * @return array{anio: int, categorias: list<array{categoria_id: int, categoria: string, activa: bool, presupuestado: string|null, ejecutado: string, porcentaje: float|null, estado: string}>, total_presupuestado: string, total_ejecutado: string, porcentaje_total: float|null}
     */
    public function comparacion(int $anio): array
    {
        $presupuestos = $this->presupuestos->delAnio($anio);
        $ejecutado = $this->egresos->totalesPorCategoriaDelAnio($anio);
        $filas = [];
        $totalPresupuestado = 0;
        $totalEjecutado = 0;

        foreach ($this->categorias->todasDe(CategoriaFinanciera::EGRESO) as $categoria) {
            $presupuesto = $presupuestos[$categoria->id] ?? null;
            $gasto = AplicacionPagoService::centavos($ejecutado[$categoria->id] ?? '0');

            if (! $categoria->activa && $presupuesto === null && $gasto === 0) {
                continue;
            }

            $planeado = $presupuesto === null ? null : AplicacionPagoService::centavos($presupuesto->monto);
            $totalPresupuestado += $planeado ?? 0;
            $totalEjecutado += $gasto;

            $filas[] = [
                'categoria_id' => $categoria->id,
                'categoria' => $categoria->nombre,
                'activa' => $categoria->activa,
                'presupuestado' => $planeado === null ? null : AplicacionPagoService::monto($planeado),
                'ejecutado' => AplicacionPagoService::monto($gasto),
                'porcentaje' => $this->porcentaje($gasto, $planeado),
                'estado' => $this->estado($gasto, $planeado),
            ];
        }

        return [
            'anio' => $anio,
            'categorias' => $filas,
            'total_presupuestado' => AplicacionPagoService::monto($totalPresupuestado),
            'total_ejecutado' => AplicacionPagoService::monto($totalEjecutado),
            'porcentaje_total' => $this->porcentaje($totalEjecutado, $totalPresupuestado),
        ];
    }

    /**
     * Guarda los montos de un año; un monto vacío quita el presupuesto de esa categoría
     *
     * Cada cambio queda en la bitácora con el valor anterior y el nuevo.
     *
     * @param  list<array{categoria_id: int|string, monto: string|null}>  $montos
     * @return int cuántas categorías cambiaron
     */
    public function guardar(User $usuario, int $anio, array $montos): int
    {
        return $this->transaccion->ejecutar(function () use ($usuario, $anio, $montos) {
            $actuales = $this->presupuestos->delAnio($anio);
            $cambios = 0;

            foreach ($montos as $fila) {
                $categoriaId = (int) $fila['categoria_id'];
                $monto = $fila['monto'] === null || trim($fila['monto']) === ''
                    ? null
                    : AplicacionPagoService::monto(AplicacionPagoService::centavos($fila['monto']));
                $actual = $actuales[$categoriaId] ?? null;

                if ($actual === null && $monto !== null) {
                    $nuevo = $this->presupuestos->crear([
                        'residencial_id' => $usuario->residencial_id,
                        'categoria_id' => $categoriaId,
                        'anio' => $anio,
                        'monto' => $monto,
                    ]);
                    $this->auditar($usuario, 'presupuesto_registrado', $nuevo, null, $monto);
                    $cambios++;
                } elseif ($actual !== null && $monto === null) {
                    $this->auditar($usuario, 'presupuesto_eliminado', $actual, $actual->monto, null);
                    $this->presupuestos->eliminar($actual);
                    $cambios++;
                } elseif ($actual !== null && $monto !== $actual->monto) {
                    $anterior = $actual->monto;
                    $this->presupuestos->actualizar($actual, ['monto' => $monto]);
                    $this->auditar($usuario, 'presupuesto_modificado', $actual, $anterior, $monto);
                    $cambios++;
                }
            }

            return $cambios;
        });
    }

    /**
     * Porcentaje ejecutado con un decimal; null si no hay presupuesto o es cero
     */
    private function porcentaje(int $ejecutado, ?int $presupuestado): ?float
    {
        return $presupuestado === null || $presupuestado === 0 ? null : round($ejecutado * 100 / $presupuestado, 1);
    }

    /**
     * Sin presupuesto, normal, en alerta desde el 90 % o excedido sobre el 100 %
     */
    private function estado(int $ejecutado, ?int $presupuestado): string
    {
        return match (true) {
            $presupuestado === null => self::SIN_PRESUPUESTO,
            $ejecutado > $presupuestado => self::EXCEDIDO,
            $ejecutado * 100 >= $presupuestado * self::UMBRAL_ALERTA && $presupuestado > 0 => self::ALERTA,
            default => self::NORMAL,
        };
    }

    private function auditar(User $usuario, string $accion, Presupuesto $presupuesto, ?string $anterior, ?string $nuevo): void
    {
        $datos = ['anio' => $presupuesto->anio, 'categoria_id' => $presupuesto->categoria_id];

        $this->auditoria->registrar($usuario, 'tesoreria', $accion, $presupuesto, [
            'valor_anterior' => $anterior === null ? null : [...$datos, 'monto' => $anterior],
            'valor_nuevo' => $nuevo === null ? null : [...$datos, 'monto' => $nuevo],
        ]);
    }
}
