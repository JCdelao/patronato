<?php

// Cada capa solo usa la capa de abajo: presentación, negocio, persistencia

arch('the persistence layer does not depend on the upper layers')
    ->expect('Patronato\Persistencia')
    ->not->toUse(['Patronato\Negocio', 'Patronato\Presentacion']);

arch('the business layer does not depend on the presentation layer')
    ->expect('Patronato\Negocio')
    ->not->toUse('Patronato\Presentacion');

arch('the presentation layer reaches the data only through the business layer')
    ->expect('Patronato\Presentacion')
    ->not->toUse(['Patronato\Persistencia\Repositorios', 'Patronato\Persistencia\Transaccion']);

arch('only the persistence layer talks to the database, the cache and the database tools')
    ->expect(['Patronato\Negocio', 'Patronato\Presentacion'])
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Support\Facades\Cache',
        'Illuminate\Support\Facades\Process',
        'Illuminate\Database\Eloquent\Builder',
    ]);

arch('repositories live in the persistence layer')
    ->expect('Patronato\Persistencia\Repositorios')
    ->classes()
    ->toHaveSuffix('Repositorio')
    ->ignoring(['Patronato\Persistencia\Repositorios\Busqueda', 'Patronato\Persistencia\Repositorios\Repositorio']);

test('the business and presentation layers do not read or write models directly', function () {
    $prohibido = '/::query\(|::create\(|->save\(|->delete\(|->update\(|->forceFill\(|->increment\(|->decrement\(/';

    $raiz = dirname(__DIR__, 3);
    $infracciones = [];

    // Capa de negocio y código PHP de la capa de presentación
    foreach (["{$raiz}/negocio", dirname(__DIR__, 2).'/src'] as $capa) {
        $archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($capa, FilesystemIterator::SKIP_DOTS));

        foreach ($archivos as $archivo) {
            foreach (file($archivo->getPathname()) ?: [] as $numero => $linea) {
                // Los archivos subidos, como el logo, no son registros de la base
                if (preg_match($prohibido, $linea) === 1 && ! str_contains($linea, 'Storage::')) {
                    $infracciones[] = substr($archivo->getPathname(), strlen($raiz) + 1).':'.($numero + 1).' '.trim($linea);
                }
            }
        }
    }

    expect($infracciones)->toBe([]);
});
