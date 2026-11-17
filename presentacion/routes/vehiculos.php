<?php

use Illuminate\Support\Facades\Route;
use Patronato\Presentacion\Controllers\VehiculoController;

Route::middleware(['auth', 'verified'])
    ->prefix('vehiculos')
    ->name('vehiculos.')
    ->group(function () {
        Route::get('/', [VehiculoController::class, 'index'])
            ->middleware("can:permiso,'vehiculos','ver'")
            ->name('index');

        Route::middleware("can:permiso,'vehiculos','registrar'")->group(function () {
            Route::get('conductores/{vivienda}', [VehiculoController::class, 'conductores'])->whereNumber('vivienda')->name('conductores');
            Route::post('/', [VehiculoController::class, 'store'])->name('store');
            Route::put('{vehiculo}', [VehiculoController::class, 'update'])->whereNumber('vehiculo')->name('update');
        });

        // Un vehículo no se borra: se da de baja y su placa queda libre
        Route::post('{vehiculo}/baja', [VehiculoController::class, 'baja'])
            ->whereNumber('vehiculo')
            ->middleware("can:permiso,'vehiculos','anular'")
            ->name('baja');
    });
