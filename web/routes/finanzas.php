<?php

use Illuminate\Support\Facades\Route;
use Patronato\Modules\Finanzas\Controllers\ModalidadController;

Route::middleware(['auth', 'verified'])
    ->prefix('finanzas')
    ->name('finanzas.')
    ->group(function () {
        Route::get('modalidad', [ModalidadController::class, 'index'])
            ->middleware("can:permiso,'parametros','ver'")
            ->name('modalidad.index');

        Route::put('modalidad', [ModalidadController::class, 'update'])
            ->middleware("can:permiso,'parametros','registrar'")
            ->name('modalidad.update');
    });
