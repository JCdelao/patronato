<?php

use Illuminate\Support\Facades\Route;
use Patronato\Presentacion\Controllers\SolicitudCorreccionController;

// Bandeja de solicitudes de corrección de la Junta Directiva (HU-54)
Route::middleware(['auth', 'verified'])
    ->prefix('solicitudes')
    ->name('solicitudes.')
    ->group(function () {
        Route::middleware("can:permiso,'solicitudes','ver'")->group(function () {
            Route::get('/', [SolicitudCorreccionController::class, 'index'])->name('index');
            Route::get('{solicitud}/adjunto', [SolicitudCorreccionController::class, 'adjunto'])
                ->whereNumber('solicitud')
                ->name('adjunto');
        });

        Route::middleware("can:permiso,'solicitudes','registrar'")->group(function () {
            Route::post('{solicitud}/revisar', [SolicitudCorreccionController::class, 'revisar'])->whereNumber('solicitud')->name('revisar');
            Route::post('{solicitud}/resolver', [SolicitudCorreccionController::class, 'resolver'])->whereNumber('solicitud')->name('resolver');
            Route::post('{solicitud}/rechazar', [SolicitudCorreccionController::class, 'rechazar'])->whereNumber('solicitud')->name('rechazar');
        });
    });
