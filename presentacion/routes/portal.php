<?php

use Illuminate\Support\Facades\Route;
use Patronato\Presentacion\Controllers\PortalEstadoCuentaController;
use Patronato\Presentacion\Controllers\PortalPreautorizacionController;
use Patronato\Presentacion\Controllers\PortalQrController;
use Patronato\Presentacion\Controllers\PortalSolicitudController;
use Patronato\Presentacion\Controllers\PortalTransferenciaController;
use Patronato\Presentacion\Controllers\PortalVehiculoController;

// Portal del residente; además del permiso, el servicio exige un residente activo vinculado
Route::middleware(['auth', 'verified'])
    ->prefix('portal')
    ->name('portal.')
    ->group(function () {
        Route::middleware("can:permiso,'portal','ver'")->group(function () {
            Route::get('estado-de-cuenta', [PortalEstadoCuentaController::class, 'index'])->name('estado-cuenta.index');
            Route::get('estado-de-cuenta/{vivienda}', [PortalEstadoCuentaController::class, 'show'])
                ->whereNumber('vivienda')
                ->name('estado-cuenta.show');
            Route::get('estado-de-cuenta/{vivienda}/pdf', [PortalEstadoCuentaController::class, 'pdf'])
                ->whereNumber('vivienda')
                ->middleware('throttle:20,1')
                ->name('estado-cuenta.pdf');
            Route::get('transferencias', [PortalTransferenciaController::class, 'index'])->name('transferencias.index');
            Route::get('transferencias/{pago}/comprobante', [PortalTransferenciaController::class, 'comprobante'])
                ->whereNumber('pago')
                ->name('transferencias.comprobante');
            Route::get('mis-vehiculos', [PortalVehiculoController::class, 'index'])->name('vehiculos.index');
            Route::get('mis-vehiculos/{vehiculo}/comprobante', [PortalVehiculoController::class, 'comprobante'])
                ->whereNumber('vehiculo')
                ->name('vehiculos.comprobante');
            Route::get('solicitudes', [PortalSolicitudController::class, 'index'])->name('solicitudes.index');
            Route::get('solicitudes/{solicitud}/adjunto', [PortalSolicitudController::class, 'adjunto'])
                ->whereNumber('solicitud')
                ->name('solicitudes.adjunto');
        });

        Route::middleware("can:permiso,'portal','registrar'")->group(function () {
            Route::get('preautorizar-visita', [PortalPreautorizacionController::class, 'create'])->name('preautorizaciones.create');
            Route::post('preautorizaciones', [PortalPreautorizacionController::class, 'store'])
                ->middleware('throttle:10,1')->name('preautorizaciones.store');
            Route::get('mi-qr', [PortalQrController::class, 'show'])->name('qr.show');

            Route::middleware('throttle:10,1')->group(function () {
                Route::post('mi-qr', [PortalQrController::class, 'emitir'])->name('qr.emitir');
                Route::post('mi-qr/regenerar', [PortalQrController::class, 'regenerar'])->name('qr.regenerar');
            });

            Route::post('solicitudes', [PortalSolicitudController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('solicitudes.store');

            Route::get('transferencias/reportar', [PortalTransferenciaController::class, 'create'])->name('transferencias.create');
            Route::post('transferencias', [PortalTransferenciaController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('transferencias.store');
        });
    });
