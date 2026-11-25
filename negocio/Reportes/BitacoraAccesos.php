<?php

namespace Patronato\Negocio\Reportes;

/**
 * Entradas, salidas e incidencias con los filtros que se usaron, lista para exportar
 *
 * Las fechas de los eventos van en hora de Honduras como AAAA-MM-DD HH:MM.
 */
final class BitacoraAccesos
{
    /**
     * @param  array{desde: string, hasta: string, tipo: string|null, turno: string|null, guardia: string|null, vivienda: string|null, medio: string|null}  $filtros  con los nombres para mostrarlos
     * @param  array{entrada: int, salida: int, incidencia: int}  $totales
     * @param  list<array{tipo: string, id: int, fecha: string, guardia: string, turno: string|null, vivienda: string|null, medio: string|null, visitante: string|null, placa: string|null, categoria: string|null, detalle: string|null, urgente: bool}>  $eventos
     */
    public function __construct(
        public readonly array $filtros,
        public readonly array $totales,
        public readonly array $eventos,
    ) {}
}
