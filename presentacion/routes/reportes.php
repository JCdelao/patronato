<?php

use Illuminate\Support\Facades\Route;
use Patronato\Negocio\Services\InformeFinancieroService;
use Patronato\Negocio\Services\ReporteCobrosService;
use Patronato\Presentacion\Controllers\InformeFinancieroController;
use Patronato\Presentacion\Controllers\ReporteCobrosController;

Route::middleware(['auth', 'verified', "can:permiso,'reportes','ver'"])
    ->prefix('reportes')
    ->name('reportes.')
    ->group(function () {
        Route::get('cobros', [ReporteCobrosController::class, 'index'])->name('cobros.index');
        Route::get('cobros/exportar/{formato}', [ReporteCobrosController::class, 'exportar'])
            ->whereIn('formato', ReporteCobrosService::FORMATOS)
            ->middleware('throttle:20,1')
            ->name('cobros.exportar');

        Route::get('financiero', [InformeFinancieroController::class, 'index'])->name('financiero.index');
        Route::get('financiero/exportar/{formato}', [InformeFinancieroController::class, 'exportar'])
            ->whereIn('formato', InformeFinancieroService::FORMATOS)
            ->middleware('throttle:20,1')
            ->name('financiero.exportar');

        // Solo quien registra ingresos y egresos fija el saldo de apertura
        Route::post('financiero/apertura', [InformeFinancieroController::class, 'apertura'])
            ->middleware("can:permiso,'tesoreria','registrar'")
            ->name('financiero.apertura');
    });
