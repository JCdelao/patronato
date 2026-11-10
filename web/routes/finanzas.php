<?php

use Illuminate\Support\Facades\Route;
use Patronato\Modules\Finanzas\Controllers\CobroController;
use Patronato\Modules\Finanzas\Controllers\ModalidadController;

Route::middleware(['auth', 'verified'])
    ->prefix('finanzas')
    ->name('finanzas.')
    ->group(function () {
        Route::middleware("can:permiso,'cobros','registrar'")->group(function () {
            Route::get('cobros/efectivo', [CobroController::class, 'create'])->name('cobros.create');
            Route::post('cobros/efectivo', [CobroController::class, 'store'])->name('cobros.store');
        });

        Route::get('modalidad', [ModalidadController::class, 'index'])
            ->middleware("can:permiso,'parametros','ver'")
            ->name('modalidad.index');

        Route::put('modalidad', [ModalidadController::class, 'update'])
            ->middleware("can:permiso,'parametros','registrar'")
            ->name('modalidad.update');
    });
