<?php

use Illuminate\Support\Facades\Route;
use Patronato\Negocio\Services\ReporteCobrosService;
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
    });
