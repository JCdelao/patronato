<?php

use Illuminate\Support\Facades\Route;
use Patronato\Modules\Padron\Controllers\ResidenteController;
use Patronato\Modules\Padron\Controllers\ViviendaController;

Route::middleware(['auth', 'verified'])
    ->prefix('padron')
    ->name('padron.')
    ->group(function () {
        Route::get('viviendas', [ViviendaController::class, 'index'])->middleware("can:permiso,'padron','ver'")->name('viviendas.index');

        Route::middleware("can:permiso,'padron','registrar'")->group(function () {
            Route::post('viviendas', [ViviendaController::class, 'store'])->name('viviendas.store');
            Route::put('viviendas/{vivienda}', [ViviendaController::class, 'update'])->whereNumber('vivienda')->name('viviendas.update');
            Route::post('viviendas/{vivienda}/reactivar', [ViviendaController::class, 'reactivar'])->whereNumber('vivienda')->name('viviendas.reactivar');

            Route::post('residentes', [ResidenteController::class, 'store'])->name('residentes.store');
            Route::put('residentes/{residente}', [ResidenteController::class, 'update'])->whereNumber('residente')->name('residentes.update');
        });

        Route::middleware("can:permiso,'padron','anular'")->group(function () {
            Route::delete('viviendas/{vivienda}', [ViviendaController::class, 'destroy'])->whereNumber('vivienda')->name('viviendas.destroy');
            Route::delete('residentes/{residente}', [ResidenteController::class, 'destroy'])->whereNumber('residente')->name('residentes.destroy');
        });
    });
