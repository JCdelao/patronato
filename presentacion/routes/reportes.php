<?php

use Illuminate\Support\Facades\Route;
use Patronato\Negocio\Services\InformeFinancieroService;
use Patronato\Negocio\Services\ReporteCobrosService;
use Patronato\Negocio\Services\ReporteMorosidadService;
use Patronato\Presentacion\Controllers\InformeFinancieroController;
use Patronato\Presentacion\Controllers\ReporteCobrosController;
use Patronato\Presentacion\Controllers\ReporteMorosidadController;
use Patronato\Presentacion\Controllers\ReporteRecaudacionController;

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

        Route::get('morosidad', [ReporteMorosidadController::class, 'index'])->name('morosidad.index');
        Route::get('morosidad/exportar/{formato}', [ReporteMorosidadController::class, 'exportar'])
            ->whereIn('formato', ReporteMorosidadService::FORMATOS)
            ->middleware('throttle:20,1')
            ->name('morosidad.exportar');

        Route::get('recaudacion', [ReporteRecaudacionController::class, 'index'])->name('recaudacion.index');

        // Solo quien registra ingresos y egresos fija el saldo de apertura
        Route::post('financiero/apertura', [InformeFinancieroController::class, 'apertura'])
            ->middleware("can:permiso,'tesoreria','registrar'")
            ->name('financiero.apertura');
    });
