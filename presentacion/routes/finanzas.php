<?php

use Illuminate\Support\Facades\Route;
use Patronato\Presentacion\Controllers\AnulacionController;
use Patronato\Presentacion\Controllers\CajaController;
use Patronato\Presentacion\Controllers\CobroController;
use Patronato\Presentacion\Controllers\ComprobanteController;
use Patronato\Presentacion\Controllers\ConceptoController;
use Patronato\Presentacion\Controllers\PagoController;
use Patronato\Presentacion\Controllers\ParametroCobroController;
use Patronato\Presentacion\Controllers\TarifaEspecialController;
use Patronato\Presentacion\Controllers\TipoViviendaController;
use Patronato\Presentacion\Controllers\TransferenciaController;
use Patronato\Presentacion\Controllers\TransferenciaReportadaController;

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

        Route::middleware("can:permiso,'cobros','ver'")->group(function () {
            Route::get('pagos', [PagoController::class, 'index'])->name('pagos.index');
            Route::get('pagos/{pago}', [PagoController::class, 'show'])->whereNumber('pago')->name('pagos.show');
            Route::get('pagos/{pago}/transferencia/comprobante', [TransferenciaController::class, 'comprobante'])
                ->whereNumber('pago')
                ->name('transferencias.comprobante');
        });

        // Registrar transferencias no da permiso de cobrar en efectivo (cobros.registrar)
        Route::middleware("can:permiso,'transferencias','registrar'")->group(function () {
            Route::get('transferencias/registrar', [TransferenciaController::class, 'create'])->name('transferencias.create');
            Route::post('transferencias', [TransferenciaController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('transferencias.store');

            // Enlace del aviso de un reporte del portal (HU-25); no requiere cobros.ver
            Route::get('transferencias/reportes/{pago}', [TransferenciaReportadaController::class, 'show'])
                ->whereNumber('pago')
                ->name('transferencias.reportes.show');
            Route::get('transferencias/reportes/{pago}/comprobante', [TransferenciaReportadaController::class, 'comprobante'])
                ->whereNumber('pago')
                ->name('transferencias.reportes.comprobante');
        });

        Route::post('pagos/{pago}/anulacion', [PagoController::class, 'solicitarAnulacion'])
            ->whereNumber('pago')
            ->middleware("can:permiso,'cobros','anular'")
            ->name('pagos.anulacion');

        // Un segundo directivo aprueba o rechaza; el servicio impide resolver la propia solicitud
        Route::middleware("can:permiso,'cobros','aprobar'")->group(function () {
            Route::get('anulaciones', [AnulacionController::class, 'index'])->name('anulaciones.index');
            Route::post('anulaciones/{solicitud}/aprobar', [AnulacionController::class, 'aprobar'])->whereNumber('solicitud')->name('anulaciones.aprobar');
            Route::post('anulaciones/{solicitud}/rechazar', [AnulacionController::class, 'rechazar'])->whereNumber('solicitud')->name('anulaciones.rechazar');
        });

        Route::middleware("can:permiso,'cajas','registrar'")->group(function () {
            Route::get('cajas', [CajaController::class, 'index'])->name('cajas.index');
            Route::get('cajas/{caja}', [CajaController::class, 'show'])->whereNumber('caja')->name('cajas.show');
            Route::post('cajas/{caja}/cerrar', [CajaController::class, 'cerrar'])->whereNumber('caja')->name('cajas.cerrar');
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
