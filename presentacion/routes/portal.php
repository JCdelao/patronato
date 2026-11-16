<?php

use Illuminate\Support\Facades\Route;
use Patronato\Presentacion\Controllers\PortalTransferenciaController;

// Portal del residente; además del permiso, el servicio exige un residente activo vinculado
Route::middleware(['auth', 'verified'])
    ->prefix('portal')
    ->name('portal.')
    ->group(function () {
        Route::middleware("can:permiso,'portal','ver'")->group(function () {
            Route::get('transferencias', [PortalTransferenciaController::class, 'index'])->name('transferencias.index');
            Route::get('transferencias/{pago}/comprobante', [PortalTransferenciaController::class, 'comprobante'])
                ->whereNumber('pago')
                ->name('transferencias.comprobante');
        });

        Route::middleware("can:permiso,'portal','registrar'")->group(function () {
            Route::get('transferencias/reportar', [PortalTransferenciaController::class, 'create'])->name('transferencias.create');
            Route::post('transferencias', [PortalTransferenciaController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('transferencias.store');
        });
    });
