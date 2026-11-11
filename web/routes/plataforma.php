<?php

use Illuminate\Support\Facades\Route;
use Patronato\Presentacion\Controllers\PasswordTemporalController;
use Patronato\Presentacion\Controllers\ResidencialController;

Route::middleware(['auth'])->group(function () {
    Route::get('cambiar-password', [PasswordTemporalController::class, 'edit'])->name('password-temporal.edit');
    Route::put('cambiar-password', [PasswordTemporalController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password-temporal.update');
});

Route::middleware(['auth', 'verified', 'can:administrar-plataforma'])
    ->prefix('plataforma')
    ->name('plataforma.')
    ->group(function () {
        Route::get('residenciales', [ResidencialController::class, 'index'])->name('residenciales.index');
        Route::get('residenciales/crear', [ResidencialController::class, 'create'])->name('residenciales.create');
        Route::post('residenciales', [ResidencialController::class, 'store'])->name('residenciales.store');
        Route::post('residenciales/{residencial}/suspender', [ResidencialController::class, 'suspender'])->whereNumber('residencial')->name('residenciales.suspender');
        Route::post('residenciales/{residencial}/reactivar', [ResidencialController::class, 'reactivar'])->whereNumber('residencial')->name('residenciales.reactivar');
    });
