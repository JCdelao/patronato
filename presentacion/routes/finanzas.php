<?php

use Illuminate\Support\Facades\Route;
use Patronato\Presentacion\Controllers\CajaController;
use Patronato\Presentacion\Controllers\CobroController;
use Patronato\Presentacion\Controllers\ComprobanteController;
use Patronato\Presentacion\Controllers\ConceptoController;
use Patronato\Presentacion\Controllers\ModalidadController;

// Enlace firmado para compartir el comprobante por mensajería; se abre sin sesión
Route::get('comprobantes/{pago}', [ComprobanteController::class, 'publico'])
    ->whereNumber('pago')
    ->middleware(['signed', 'throttle:30,1'])
    ->name('comprobantes.publico');

Route::middleware(['auth', 'verified'])
    ->prefix('finanzas')
    ->name('finanzas.')
    ->group(function () {
        Route::middleware("can:permiso,'cobros','registrar'")->group(function () {
            Route::get('cobros/efectivo', [CobroController::class, 'create'])->name('cobros.create');
            Route::post('cobros/efectivo', [CobroController::class, 'store'])->name('cobros.store');
        });

        // El controlador revisa que sea el cobrador del pago o que tenga cobros.ver
        Route::get('pagos/{pago}/comprobante', [ComprobanteController::class, 'show'])->whereNumber('pago')->name('comprobantes.show');
        Route::get('pagos/{pago}/comprobante/pdf', [ComprobanteController::class, 'pdf'])->whereNumber('pago')->name('comprobantes.pdf');
        Route::post('pagos/{pago}/comprobante/correo', [ComprobanteController::class, 'enviar'])
            ->whereNumber('pago')
            ->middleware('throttle:10,1')
            ->name('comprobantes.enviar');

        Route::middleware("can:permiso,'cajas','registrar'")->group(function () {
            Route::get('cajas', [CajaController::class, 'index'])->name('cajas.index');
            Route::get('cajas/{caja}', [CajaController::class, 'show'])->whereNumber('caja')->name('cajas.show');
            Route::post('cajas/{caja}/cerrar', [CajaController::class, 'cerrar'])->whereNumber('caja')->name('cajas.cerrar');
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
