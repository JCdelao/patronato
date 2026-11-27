<?php

namespace Patronato\Negocio\Reportes;

/**
 * Viviendas con saldo pendiente, con sus meses adeudados y lo que deben
 *
 * Los montos son textos con dos decimales.
 */
final class ReporteMorosidad
{
    /**
     * @param  list<array{vivienda_id: int, codigo: string, activa: bool, responsable: string|null, saldo: string, por_verificar: string, meses_adeudados: int, deuda_desde: string|null, meses_vencidos: int, vencido: string, estado: string}>  $viviendas
     */
    public function __construct(
        public readonly string $residencial,
        public readonly string $orden,
        public readonly string $fechaCorte,
        public readonly array $viviendas,
        public readonly string $total,
        public readonly string $totalVencido,
        public readonly int $enMora,
    ) {}

    /**
     * Datos para la pantalla
     *
     * @return array<string, mixed>
     */
    public function paraPantalla(): array
    {
        return [
            'orden' => $this->orden,
            'fecha_corte' => $this->fechaCorte,
            'viviendas' => $this->viviendas,
            'total' => $this->total,
            'total_vencido' => $this->totalVencido,
            'en_mora' => $this->enMora,
        ];
    }
}
