<?php

namespace Patronato\Negocio\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Patronato\Persistencia\Models\ModalidadCobro;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\ModalidadRepositorio;
use Patronato\Persistencia\Transaccion;

class ModalidadService
{
    /**
     * Zona horaria con la que se calcula el período
     */
    private const ZONA_LOCAL = 'America/Tegucigalpa';

    public function __construct(
        private ModalidadRepositorio $modalidades,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Primer día del mes en curso
     */
    public function periodoActual(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONA_LOCAL)->startOfMonth();
    }

    /**
     * Modalidad que rige un período
     */
    public function vigente(int $residencialId, CarbonInterface $periodo): ?ModalidadCobro
    {
        return $this->modalidades->vigenteEn($residencialId, $periodo->copy()->startOfMonth()->toDateString());
    }

    /**
     * Modalidad que se usa para cobrar un período; si no se ha definido, la unificada
     */
    public function modalidadDe(int $residencialId, CarbonInterface $periodo): string
    {
        return $this->vigente($residencialId, $periodo)->modalidad ?? ModalidadCobro::UNIFICADA;
    }

    /**
     * Cambio de modalidad que todavía no entra en vigor
     */
    public function programada(int $residencialId): ?ModalidadCobro
    {
        return $this->modalidades->siguienteA($residencialId, $this->periodoActual()->toDateString());
    }

    /**
     * Guarda la modalidad y devuelve el período desde el que rige
     *
     * Un cambio de modalidad rige desde el período siguiente para no alterar
     * los cargos del período en curso.
     *
     * @param  array<int, array{concepto: string, monto: float|int|string}>  $desglose
     */
    public function guardar(User $actor, string $modalidad, array $desglose): CarbonImmutable
    {
        $residencialId = (int) $actor->residencial_id;
        $desglose = $modalidad === ModalidadCobro::UNIFICADA ? $this->normalizarDesglose($desglose) : null;

        return $this->transaccion->ejecutar(function () use ($actor, $residencialId, $modalidad, $desglose) {
            $periodo = $this->periodoActual();
            $actual = $this->vigente($residencialId, $periodo);
            $programada = $this->programada($residencialId);

            // La primera modalidad rige desde el período en curso
            if ($actual === null) {
                $fila = $this->crear($actor, $modalidad, $desglose, $periodo);

                $this->auditoria->registrar($actor, 'finanzas', 'modalidad_definida', $fila, [
                    'valor_nuevo' => $this->resumen($fila),
                ]);

                return $periodo;
            }

            if ($actual->modalidad === $modalidad) {
                if ($programada !== null) {
                    $this->auditoria->registrar($actor, 'finanzas', 'cambio_de_modalidad_cancelado', $actual, [
                        'valor_anterior' => $this->resumen($programada),
                    ]);

                    $this->modalidades->eliminar($programada);
                }

                // El desglose es informativo y se puede corregir en el período en curso
                if ($actual->desglose !== $desglose) {
                    $anterior = $this->resumen($actual);

                    $this->modalidades->actualizar($actual, ['desglose' => $desglose]);

                    $this->auditoria->registrar($actor, 'finanzas', 'desglose_actualizado', $actual, [
                        'valor_anterior' => ['desglose' => $anterior['desglose']],
                        'valor_nuevo' => ['desglose' => $desglose],
                    ]);
                }

                return $periodo;
            }

            $desde = $periodo->addMonth();
            $anterior = $programada !== null ? $this->resumen($programada) : $this->resumen($actual);

            if ($programada !== null) {
                $this->modalidades->eliminar($programada);
            }

            $fila = $this->crear($actor, $modalidad, $desglose, $desde);

            $this->auditoria->registrar($actor, 'finanzas', 'cambio_de_modalidad_programado', $fila, [
                'valor_anterior' => $anterior,
                'valor_nuevo' => $this->resumen($fila),
            ]);

            return $desde;
        });
    }

    /**
     * @param  list<array{concepto: string, monto: string}>|null  $desglose
     */
    private function crear(User $actor, string $modalidad, ?array $desglose, CarbonInterface $desde): ModalidadCobro
    {
        return $this->modalidades->crear([
            'residencial_id' => $actor->residencial_id,
            'modalidad' => $modalidad,
            'desglose' => $desglose,
            'vigente_desde' => $desde->toDateString(),
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @param  array<int, array{concepto: string, monto: float|int|string}>  $desglose
     * @return list<array{concepto: string, monto: string}>|null
     */
    private function normalizarDesglose(array $desglose): ?array
    {
        $filas = array_map(fn (array $fila) => [
            'concepto' => trim($fila['concepto']),
            'monto' => number_format((float) $fila['monto'], 2, '.', ''),
        ], array_values($desglose));

        return $filas === [] ? null : $filas;
    }

    /**
     * @return array{modalidad: string, vigente_desde: string, desglose: list<array{concepto: string, monto: string}>|null}
     */
    private function resumen(ModalidadCobro $fila): array
    {
        return [
            'modalidad' => $fila->modalidad,
            'vigente_desde' => $fila->vigente_desde->toDateString(),
            'desglose' => $fila->desglose,
        ];
    }
}
