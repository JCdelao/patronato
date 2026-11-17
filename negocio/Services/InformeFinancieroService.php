<?php

namespace Patronato\Negocio\Services;

use InvalidArgumentException;
use Patronato\Negocio\Reportes\ConstructorInformeFinanciero;
use Patronato\Negocio\Reportes\ExportadorInforme;
use Patronato\Negocio\Reportes\ExportadorInformeExcel;
use Patronato\Negocio\Reportes\ExportadorInformePdf;
use Patronato\Negocio\Reportes\InformeFinanciero;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\InformeFinancieroRepositorio;
use Patronato\Persistencia\Transaccion;

/**
 * Informe financiero mensual y anual: saldo inicial, ingresos y egresos por categoría y saldo final
 */
class InformeFinancieroService
{
    /**
     * Formatos de exportación disponibles
     */
    public const FORMATOS = ['pdf', 'excel'];

    public function __construct(
        private InformeFinancieroRepositorio $movimientos,
        private ConfiguracionService $configuracion,
        private PeriodoService $periodos,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
        private ExportadorInformePdf $pdf,
        private ExportadorInformeExcel $excel,
    ) {}

    /**
     * Sin filtros, el informe es el del mes en curso
     *
     * @return array{tipo: string, anio: int, mes: int}
     */
    public function periodoPredeterminado(): array
    {
        $hoy = $this->periodos->hoy();

        return ['tipo' => ConstructorInformeFinanciero::MENSUAL, 'anio' => $hoy->year, 'mes' => $hoy->month];
    }

    /**
     * Arma el informe del residencial del usuario
     *
     * @param  array{tipo: string, anio: int, mes: int}  $periodo
     */
    public function generar(User $usuario, array $periodo): InformeFinanciero
    {
        $residencialId = (int) $usuario->residencial_id;
        $apertura = $this->configuracion->apertura($residencialId);
        $constructor = new ConstructorInformeFinanciero($this->movimientos);

        $periodo['tipo'] === ConstructorInformeFinanciero::ANUAL
            ? $constructor->anual($periodo['anio'])
            : $constructor->mensual($periodo['anio'], $periodo['mes']);

        return $constructor
            ->conApertura($apertura['monto'], $apertura['fecha'])
            ->hasta($this->periodos->hoy()->toDateString())
            ->deResidencial((string) $usuario->residencial?->nombre)
            ->construir();
    }

    /**
     * @return array{monto: string|null, fecha: string|null}
     */
    public function apertura(User $usuario): array
    {
        return $this->configuracion->apertura((int) $usuario->residencial_id);
    }

    /**
     * Registra o corrige el saldo de apertura; queda en la bitácora con el valor anterior
     */
    public function registrarApertura(User $usuario, string $monto, string $fecha): void
    {
        $residencialId = (int) $usuario->residencial_id;
        $monto = number_format((float) $monto, 2, '.', '');

        $this->transaccion->ejecutar(function () use ($usuario, $residencialId, $monto, $fecha) {
            $anterior = $this->configuracion->apertura($residencialId);

            $this->configuracion->guardarApertura($residencialId, $monto, $fecha);

            if ($usuario->residencial !== null) {
                $this->auditoria->registrar($usuario, 'tesoreria', 'apertura_registrada', $usuario->residencial, [
                    'valor_anterior' => $anterior,
                    'valor_nuevo' => ['monto' => $monto, 'fecha' => $fecha],
                ]);
            }
        });
    }

    /**
     * Elige el exportador del formato pedido
     */
    public function exportador(string $formato): ExportadorInforme
    {
        return match ($formato) {
            'pdf' => $this->pdf,
            'excel' => $this->excel,
            default => throw new InvalidArgumentException("Formato no disponible: {$formato}"),
        };
    }

    /**
     * Archivo del informe en el formato pedido
     *
     * @param  array{tipo: string, anio: int, mes: int}  $periodo
     * @return array{contenido: string, tipo: string, nombre: string}
     */
    public function exportar(User $usuario, array $periodo, string $formato): array
    {
        $exportador = $this->exportador($formato);
        $informe = $this->generar($usuario, $periodo);
        $sufijo = $periodo['tipo'] === ConstructorInformeFinanciero::ANUAL
            ? (string) $periodo['anio']
            : sprintf('%04d-%02d', $periodo['anio'], $periodo['mes']);

        return [
            'contenido' => $exportador->exportar($informe),
            'tipo' => $exportador->tipoContenido(),
            'nombre' => "informe-financiero-{$sufijo}.".$exportador->extension(),
        ];
    }
}
