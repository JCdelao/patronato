<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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
