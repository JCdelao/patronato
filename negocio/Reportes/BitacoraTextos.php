<?php

namespace Patronato\Negocio\Reportes;

/**
 * Nombres de los tipos de evento y de turno para mostrar en la bitácora exportada
 */
final class BitacoraTextos
{
    public const TIPOS = [
        'entrada' => 'Entrada',
        'salida' => 'Salida',
        'incidencia' => 'Incidencia',
    ];

    public const TURNOS = [
        'dia' => 'Día',
        'tarde' => 'Tarde',
        'noche' => 'Noche',
        '24_horas' => '24 horas',
    ];

    public static function tipo(?string $tipo): string
    {
        return $tipo === null ? 'Todos' : (self::TIPOS[$tipo] ?? $tipo);
    }

    public static function turno(?string $turno, string $vacio = 'Sin turno'): string
    {
        return $turno === null ? $vacio : (self::TURNOS[$turno] ?? $turno);
    }
}
