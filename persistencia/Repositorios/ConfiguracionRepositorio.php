<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Support\Facades\DB;

/**
 * Configuración propia de cada residencial
 */
class ConfiguracionRepositorio
{
    /**
     * Valores guardados de las columnas pedidas
     *
     * @param  array<int, string>  $columnas
     * @return array<string, mixed>|null
     */
    public function valores(int $residencialId, array $columnas): ?array
    {
        $fila = DB::table('configuracion_residencial')->where('residencial_id', $residencialId)->first($columnas);

        return $fila === null ? null : (array) $fila;
    }

    public function valor(int $residencialId, string $columna): mixed
    {
        return DB::table('configuracion_residencial')->where('residencial_id', $residencialId)->value($columna);
    }

    /**
     * Crea la configuración con los valores predeterminados
     */
    public function crear(int $residencialId): void
    {
        DB::table('configuracion_residencial')->insert([
            'residencial_id' => $residencialId,
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function guardar(int $residencialId, array $datos): void
    {
        DB::table('configuracion_residencial')->updateOrInsert(
            ['residencial_id' => $residencialId],
            [...$datos, 'updated_at' => now()],
        );
    }
}
