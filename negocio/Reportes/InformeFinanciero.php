<?php

namespace Patronato\Negocio\Reportes;

/**
 * Informe financiero de un mes o de un año, listo para mostrar o exportar
 *
 * Los montos son textos con dos decimales. Siempre cuadra:
 * saldo final = saldo inicial + saldo de apertura del período + ingresos − egresos.
 */
final class InformeFinanciero
{
    /**
     * @param  array{fecha: string, monto: string}|null  $apertura  saldo de apertura, solo si su fecha cae en el período
     * @param  list<array{categoria: string, total: string}>  $ingresos
     * @param  list<array{categoria: string, total: string}>  $egresos
     * @param  list<array{mes: string, saldo_inicial: string, apertura: string|null, ingresos: string, egresos: string, saldo_final: string}>  $meses  solo en el anual
     */
    public function __construct(
        public readonly string $tipo,
        public readonly string $titulo,
        public readonly string $desde,
        public readonly string $hasta,
        public readonly string $residencial,
        public readonly string $saldoInicial,
        public readonly ?array $apertura,
        public readonly array $ingresos,
        public readonly array $egresos,
        public readonly string $totalIngresos,
        public readonly string $totalEgresos,
        public readonly string $saldoFinal,
        public readonly array $meses,
    ) {}

    /**
     * Datos para la pantalla
     *
     * @return array<string, mixed>
     */
    public function paraPantalla(): array
    {
        return [
            'tipo' => $this->tipo,
            'titulo' => $this->titulo,
            'desde' => $this->desde,
            'hasta' => $this->hasta,
            'saldo_inicial' => $this->saldoInicial,
            'apertura' => $this->apertura,
            'ingresos' => $this->ingresos,
            'egresos' => $this->egresos,
            'total_ingresos' => $this->totalIngresos,
            'total_egresos' => $this->totalEgresos,
            'saldo_final' => $this->saldoFinal,
            'meses' => $this->meses,
        ];
    }
}
