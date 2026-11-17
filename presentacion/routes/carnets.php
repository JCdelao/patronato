<?php

use Illuminate\Support\Facades\Route;
use Patronato\Presentacion\Controllers\CarnetController;

Route::middleware(['auth', 'verified'])
    ->prefix('carnets')
    ->name('carnets.')
    ->group(function () {
        Route::get('/', [CarnetController::class, 'index'])
            ->middleware("can:permiso,'carnets','ver'")
            ->name('index');

        Route::middleware("can:permiso,'carnets','registrar'")->group(function () {
            Route::post('residentes/{residente}', [CarnetController::class, 'emitir'])->whereNumber('residente')->name('emitir');
            Route::post('{carnet}/renovar', [CarnetController::class, 'renovar'])->whereNumber('carnet')->name('renovar');
            Route::post('{carnet}/estado', [CarnetController::class, 'cambiarEstado'])->whereNumber('carnet')->name('estado');
        });

        // Un carnet no se borra: se da de baja con su motivo
        Route::post('{carnet}/baja', [CarnetController::class, 'baja'])
            ->whereNumber('carnet')
            ->middleware("can:permiso,'carnets','anular'")
            ->name('baja');
    });
