<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Patronato\Persistencia\Models\TipoVivienda;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

function something() {}

/**
 * Id de un tipo de vivienda del residencial; lo crea si no existe
 */
function tipoVivienda(int $residencialId, string $nombre = 'Casa'): int
{
    return TipoVivienda::query()
        ->withoutGlobalScope('residencial')
        ->firstOrCreate(['residencial_id' => $residencialId, 'nombre' => $nombre])
        ->id;
}

/**
 * Lee todas las hojas de un .xlsx como arreglos de valores
 *
 * @return array<string, list<list<mixed>>>
 */
function leerExcel(string $contenido): array
{
    $archivo = tempnam(sys_get_temp_dir(), 'prueba');
    file_put_contents($archivo, $contenido);

    $lector = new Reader;
    $lector->open($archivo);
    $hojas = [];

    foreach ($lector->getSheetIterator() as $hoja) {
        foreach ($hoja->getRowIterator() as $fila) {
            $hojas[$hoja->getName()][] = $fila->toArray();
        }
    }

    $lector->close();
    unlink($archivo);

    return $hojas;
}
