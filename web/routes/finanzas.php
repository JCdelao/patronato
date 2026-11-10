<?php

use Illuminate\Support\Facades\Route;
use Patronato\Modules\Finanzas\Controllers\CobroController;
use Patronato\Modules\Finanzas\Controllers\ConceptoController;
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

        Route::get('conceptos', [ConceptoController::class, 'index'])
            ->middleware("can:permiso,'parametros','ver'")
            ->name('conceptos.index');

        Route::middleware("can:permiso,'parametros','registrar'")->group(function () {
            Route::post('conceptos', [ConceptoController::class, 'store'])->name('conceptos.store');
            Route::put('conceptos/{concepto}', [ConceptoController::class, 'update'])->whereNumber('concepto')->name('conceptos.update');
            Route::post('conceptos/{concepto}/desactivar', [ConceptoController::class, 'desactivar'])->whereNumber('concepto')->name('conceptos.desactivar');
            Route::post('conceptos/{concepto}/reactivar', [ConceptoController::class, 'reactivar'])->whereNumber('concepto')->name('conceptos.reactivar');
            Route::delete('conceptos/{concepto}', [ConceptoController::class, 'destroy'])->whereNumber('concepto')->name('conceptos.destroy');
        });
    });
