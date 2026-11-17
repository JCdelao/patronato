<?php

use Patronato\Negocio\Stickers\EstadoSticker;

// Tabla de transiciones del patrón State de los stickers

test('each sticker state allows only its own transitions', function (string $estado, array $transiciones) {
    $objeto = EstadoSticker::de($estado);

    $resultado = [];

    foreach ($objeto->acciones() as $accion) {
        $resultado[$accion] = $objeto->siguiente($accion);
    }

    expect($resultado)->toBe($transiciones);

    foreach (['suspender', 'reactivar', 'vencer', 'reemplazar', 'dar_de_baja'] as $accion) {
        expect($objeto->permite($accion))->toBe(array_key_exists($accion, $transiciones));
    }
})->with([
    'active' => ['activo', ['suspender' => 'suspendido', 'vencer' => 'vencido', 'reemplazar' => 'reemplazado', 'dar_de_baja' => 'dado_de_baja']],
    'suspended' => ['suspendido', ['reactivar' => 'activo', 'reemplazar' => 'reemplazado', 'dar_de_baja' => 'dado_de_baja']],
    'expired' => ['vencido', ['reemplazar' => 'reemplazado', 'dar_de_baja' => 'dado_de_baja']],
    'replaced' => ['reemplazado', []],
    'deactivated' => ['dado_de_baja', []],
]);

test('an unknown sticker state is rejected', function () {
    EstadoSticker::de('perdido');
})->throws(InvalidArgumentException::class);
