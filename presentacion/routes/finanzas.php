<?php

use Illuminate\Support\Facades\Route;
use Patronato\Presentacion\Controllers\CobroController;
use Patronato\Presentacion\Controllers\ConceptoController;
use Patronato\Presentacion\Controllers\ParametroCobroController;
use Patronato\Presentacion\Controllers\TarifaEspecialController;
use Patronato\Presentacion\Controllers\TipoViviendaController;

Route::middleware(['auth', 'verified'])
    ->prefix('finanzas')
    ->name('finanzas.')
    ->group(function () {
        Route::middleware("can:permiso,'cobros','registrar'")->group(function () {
            Route::get('cobros/efectivo', [CobroController::class, 'create'])->name('cobros.create');
            Route::post('cobros/efectivo', [CobroController::class, 'store'])->name('cobros.store');
        });

        Route::get('parametros', [ParametroCobroController::class, 'index'])
            ->middleware("can:permiso,'parametros','ver'")
            ->name('parametros.index');

        Route::middleware("can:permiso,'parametros','registrar'")->group(function () {
            Route::post('conceptos', [ConceptoController::class, 'store'])->name('conceptos.store');
            Route::put('conceptos/{concepto}', [ConceptoController::class, 'update'])->whereNumber('concepto')->name('conceptos.update');
            Route::post('conceptos/{concepto}/desactivar', [ConceptoController::class, 'desactivar'])->whereNumber('concepto')->name('conceptos.desactivar');
            Route::post('conceptos/{concepto}/reactivar', [ConceptoController::class, 'reactivar'])->whereNumber('concepto')->name('conceptos.reactivar');
            Route::delete('conceptos/{concepto}', [ConceptoController::class, 'destroy'])->whereNumber('concepto')->name('conceptos.destroy');

            Route::post('tipos-vivienda', [TipoViviendaController::class, 'store'])->name('tipos-vivienda.store');
            Route::put('tipos-vivienda/{tipo}', [TipoViviendaController::class, 'update'])->whereNumber('tipo')->name('tipos-vivienda.update');
            Route::post('tipos-vivienda/{tipo}/desactivar', [TipoViviendaController::class, 'desactivar'])->whereNumber('tipo')->name('tipos-vivienda.desactivar');
            Route::post('tipos-vivienda/{tipo}/reactivar', [TipoViviendaController::class, 'reactivar'])->whereNumber('tipo')->name('tipos-vivienda.reactivar');
            Route::delete('tipos-vivienda/{tipo}', [TipoViviendaController::class, 'destroy'])->whereNumber('tipo')->name('tipos-vivienda.destroy');

            Route::post('tarifas-especiales', [TarifaEspecialController::class, 'store'])->name('tarifas-especiales.store');
            Route::post('tarifas-especiales/{vivienda}/quitar', [TarifaEspecialController::class, 'quitar'])->whereNumber('vivienda')->name('tarifas-especiales.quitar');
        });
    });
