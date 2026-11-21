<?php

use Illuminate\Support\Facades\Route;
use Patronato\Presentacion\Controllers\StickerController;
use Patronato\Presentacion\Controllers\VehiculoController;

Route::middleware(['auth', 'verified'])
    ->prefix('vehiculos')
    ->name('vehiculos.')
    ->group(function () {
        Route::get('/', [VehiculoController::class, 'index'])
            ->middleware("can:permiso,'vehiculos','ver'")
            ->name('index');

        Route::get('{vehiculo}/comprobante', [VehiculoController::class, 'comprobante'])
            ->whereNumber('vehiculo')
            ->middleware("can:permiso,'vehiculos','ver'")
            ->name('comprobante');

        Route::middleware("can:permiso,'vehiculos','registrar'")->group(function () {
            Route::get('conductores/{vivienda}', [VehiculoController::class, 'conductores'])->whereNumber('vivienda')->name('conductores');
            Route::post('/', [VehiculoController::class, 'store'])->name('store');
            Route::put('{vehiculo}', [VehiculoController::class, 'update'])->whereNumber('vehiculo')->name('update');

            Route::post('{vehiculo}/stickers', [StickerController::class, 'asignar'])->whereNumber('vehiculo')->name('stickers.asignar');
            Route::post('stickers/{sticker}/reemplazar', [StickerController::class, 'reemplazar'])->whereNumber('sticker')->name('stickers.reemplazar');
            Route::post('stickers/{sticker}/estado', [StickerController::class, 'cambiarEstado'])->whereNumber('sticker')->name('stickers.estado');
        });

        // Un vehículo o un sticker no se borra: se da de baja con su motivo
        Route::post('{vehiculo}/baja', [VehiculoController::class, 'baja'])
            ->whereNumber('vehiculo')
            ->middleware("can:permiso,'vehiculos','anular'")
            ->name('baja');

        Route::post('stickers/{sticker}/baja', [StickerController::class, 'baja'])
            ->whereNumber('sticker')
            ->middleware("can:permiso,'vehiculos','anular'")
            ->name('stickers.baja');
    });
