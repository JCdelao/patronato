<?php

namespace Patronato\Modules\Padron\Services;

use Illuminate\Support\Str;

class BusquedaService
{
    private const TILDES = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
    ];

    /**
     * Pasa una columna a minúsculas y sin tildes dentro de la consulta
     *
     * @param  literal-string  $expresion
     * @return literal-string
     */
    public static function columna(string $expresion): string
    {
        $sql = "lower({$expresion})";

        foreach (self::TILDES as $con => $sin) {
            $sql = "replace({$sql}, '{$con}', '{$sin}')";
        }

        return $sql;
    }

    /**
     * Arma el patrón de búsqueda en minúsculas y sin tildes
     */
    public static function patron(string $buscar): string
    {
        return '%'.Str::lower(Str::ascii($buscar)).'%';
    }
}
