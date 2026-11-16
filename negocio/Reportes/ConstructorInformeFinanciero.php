<?php

namespace Patronato\Negocio\Reportes;

use Carbon\CarbonImmutable;
use LogicException;
use Patronato\Negocio\Services\AplicacionPagoService;
use Patronato\Persistencia\Repositorios\InformeFinancieroRepositorio;

/**
 * Arma el informe financiero paso a paso
 *
 * Uso: (new ConstructorInformeFinanciero($repositorio))->anual(2026)->conApertura('5000.00', '2026-01-01')->hasta($hoy)->construir()
 *
 * Los cálculos van en centavos para que los totales cuadren sin errores de redondeo.
 */
class ConstructorInformeFinanciero
{
    public const MENSUAL = 'mensual';

    public const ANUAL = 'anual';

    private const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    private ?string $tipo = null;

    private int $anio = 0;

    private int $mes = 1;

    private ?int $apertura = null;

    private ?string $fechaApertura = null;

    private ?string $hoy = null;

    private string $residencial = '';

    public function __construct(private InformeFinancieroRepositorio $movimientos) {}

    public function mensual(int $anio, int $mes): static
    {
        $this->tipo = self::MENSUAL;
        $this->anio = $anio;
        $this->mes = $mes;

        return $this;
    }

    public function anual(int $anio): static
    {
        $this->tipo = self::ANUAL;
        $this->anio = $anio;

        return $this;
    }

    /**
     * Saldo con el que el patronato empezó a registrar; null si no se ha registrado
     */
    public function conApertura(?string $monto, ?string $fecha): static
    {
        $this->apertura = $monto === null || $fecha === null ? null : AplicacionPagoService::centavos($monto);
        $this->fechaApertura = $this->apertura === null ? null : $fecha;

        return $this;
    }

    /**
     * Día de hoy: el anual del año en curso no muestra los meses que faltan
     */
    public function hasta(string $hoy): static
    {
        $this->hoy = $hoy;

        return $this;
    }

    public function deResidencial(string $nombre): static
    {
        $this->residencial = $nombre;

        return $this;
    }

    public function construir(): InformeFinanciero
    {
        if ($this->tipo === null) {
            throw new LogicException('Elige si el informe es mensual o anual.');
        }

        $inicio = CarbonImmutable::parse(sprintf('%04d-%02d-01', $this->anio, $this->tipo === self::MENSUAL ? $this->mes : 1));
        $fin = $this->tipo === self::MENSUAL ? $inicio->endOfMonth() : $inicio->endOfYear();
        $desde = $inicio->toDateString();
        $hasta = $fin->toDateString();

        $saldoInicial = $this->saldoAl($desde);
        $apertura = $this->aperturaEntre($desde, $hasta);
        $categorias = array_map($this->normalizar(...), $this->movimientos->totalesPorCategoria($desde, $hasta));
        $ingresos = $this->sumar($categorias['ingresos']);
        $egresos = $this->sumar($categorias['egresos']);

        return new InformeFinanciero(
            tipo: $this->tipo,
            titulo: $this->tipo === self::MENSUAL
                ? self::MESES[$this->mes - 1]." de {$this->anio}"
                : "Año {$this->anio}",
            desde: $desde,
            hasta: $hasta,
            residencial: $this->residencial,
            saldoInicial: AplicacionPagoService::monto($saldoInicial),
            apertura: $apertura === null ? null : ['fecha' => (string) $this->fechaApertura, 'monto' => AplicacionPagoService::monto($apertura)],
            ingresos: $categorias['ingresos'],
            egresos: $categorias['egresos'],
            totalIngresos: AplicacionPagoService::monto($ingresos),
            totalEgresos: AplicacionPagoService::monto($egresos),
            saldoFinal: AplicacionPagoService::monto($saldoInicial + ($apertura ?? 0) + $ingresos - $egresos),
            meses: $this->tipo === self::ANUAL ? $this->meses($saldoInicial, $desde, $hasta) : [],
        );
    }

    /**
     * Saldo al empezar el día: lo acumulado antes y la apertura si ya había ocurrido
     */
    private function saldoAl(string $fecha): int
    {
        $antes = $this->movimientos->totalesAntesDe($fecha);
        $apertura = $this->fechaApertura !== null && $this->fechaApertura < $fecha ? (int) $this->apertura : 0;

        return $apertura + AplicacionPagoService::centavos($antes['ingresos']) - AplicacionPagoService::centavos($antes['egresos']);
    }

    private function aperturaEntre(string $desde, string $hasta): ?int
    {
        return $this->fechaApertura !== null && $this->fechaApertura >= $desde && $this->fechaApertura <= $hasta
            ? $this->apertura
            : null;
    }

    /**
     * Una fila por mes, encadenando el saldo final de cada mes con el inicial del siguiente
     *
     * @return list<array{mes: string, saldo_inicial: string, apertura: string|null, ingresos: string, egresos: string, saldo_final: string}>
     */
    private function meses(int $saldoInicial, string $desde, string $hasta): array
    {
        $porDia = $this->movimientos->totalesPorDia($desde, $hasta);
        $ultimoMes = $this->hoy !== null && str_starts_with($this->hoy, (string) $this->anio) ? (int) substr($this->hoy, 5, 2) : 12;
        $saldo = $saldoInicial;
        $meses = [];

        for ($mes = 1; $mes <= $ultimoMes; $mes++) {
            $clave = sprintf('%04d-%02d', $this->anio, $mes);
            $inicio = "{$clave}-01";
            $apertura = $this->aperturaEntre($inicio, CarbonImmutable::parse($inicio)->endOfMonth()->toDateString());
            $ingresos = $this->sumarDelMes($porDia['ingresos'], $clave);
            $egresos = $this->sumarDelMes($porDia['egresos'], $clave);
            $final = $saldo + ($apertura ?? 0) + $ingresos - $egresos;

            $meses[] = [
                'mes' => $clave,
                'saldo_inicial' => AplicacionPagoService::monto($saldo),
                'apertura' => $apertura === null ? null : AplicacionPagoService::monto($apertura),
                'ingresos' => AplicacionPagoService::monto($ingresos),
                'egresos' => AplicacionPagoService::monto($egresos),
                'saldo_final' => AplicacionPagoService::monto($final),
            ];

            $saldo = $final;
        }

        return $meses;
    }

    /**
     * Deja cada total con dos decimales, como el resto del informe
     *
     * @param  list<array{categoria: string, total: string}>  $filas
     * @return list<array{categoria: string, total: string}>
     */
    private function normalizar(array $filas): array
    {
        return array_map(fn (array $fila): array => [
            'categoria' => $fila['categoria'],
            'total' => AplicacionPagoService::monto(AplicacionPagoService::centavos($fila['total'])),
        ], $filas);
    }

    /**
     * @param  list<array{categoria: string, total: string}>  $filas
     */
    private function sumar(array $filas): int
    {
        return array_sum(array_map(fn (array $fila): int => AplicacionPagoService::centavos($fila['total']), $filas));
    }

    /**
     * @param  array<string, string>  $porDia
     */
    private function sumarDelMes(array $porDia, string $mes): int
    {
        $total = 0;

        foreach ($porDia as $dia => $monto) {
            if (str_starts_with($dia, $mes)) {
                $total += AplicacionPagoService::centavos($monto);
            }
        }

        return $total;
    }
}
