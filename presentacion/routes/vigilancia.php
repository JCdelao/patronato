<?php

use Illuminate\Support\Facades\Route;
use Patronato\Negocio\Services\BitacoraAccesosService;
use Patronato\Presentacion\Controllers\AutorizadoFrecuenteController;
use Patronato\Presentacion\Controllers\BitacoraAccesosController;
use Patronato\Presentacion\Controllers\EntregaTurnoController;
use Patronato\Presentacion\Controllers\EscaneoQrController;
use Patronato\Presentacion\Controllers\GuardiaController;
use Patronato\Presentacion\Controllers\IncidenciaController;
use Patronato\Presentacion\Controllers\MedioEntradaController;
use Patronato\Presentacion\Controllers\PreautorizacionController;
use Patronato\Presentacion\Controllers\TipoIncidenciaController;
use Patronato\Presentacion\Controllers\TurnoController;
use Patronato\Presentacion\Controllers\VisitaController;

Route::middleware(['auth', 'verified'])
    ->prefix('vigilancia')
    ->name('vigilancia.')
    ->group(function () {
        Route::middleware("can:permiso,'bitacora','ver'")->group(function () {
            Route::get('bitacora', [BitacoraAccesosController::class, 'index'])->name('bitacora.index');
            Route::get('bitacora/exportar/{formato}', [BitacoraAccesosController::class, 'exportar'])
                ->whereIn('formato', BitacoraAccesosService::FORMATOS)
                ->middleware('throttle:20,1')
                ->name('bitacora.exportar');
        });

        Route::middleware("can:permiso,'visitas','registrar'")->group(function () {
            Route::get('escanear-qr', [EscaneoQrController::class, 'index'])->name('qr.index');
            Route::middleware('throttle:30,1,hu48-qr')->group(function () {
                Route::post('escanear-qr', [EscaneoQrController::class, 'store'])->name('qr.store');
                Route::post('escanear-qr/{escaneo}/decision', [EscaneoQrController::class, 'decidir'])->whereNumber('escaneo')->name('qr.decidir');
            });
            Route::get('escanear-qr/{escaneo}/foto', [EscaneoQrController::class, 'foto'])->whereNumber('escaneo')->name('qr.foto');

            Route::get('viviendas/{vivienda}/autorizados-frecuentes', [AutorizadoFrecuenteController::class, 'index'])
                ->whereNumber('vivienda')->name('autorizados-frecuentes.index');
            Route::get('viviendas/{vivienda}/preautorizaciones', [PreautorizacionController::class, 'index'])
                ->whereNumber('vivienda')->name('preautorizaciones.index');
            Route::get('entrada-visitas', [VisitaController::class, 'create'])->name('visitas.create');
            Route::post('entrada-visitas', [VisitaController::class, 'store'])->name('visitas.store');
            Route::post('entrada-visitas/{visita}/salida', [VisitaController::class, 'salida'])->whereNumber('visita')->name('visitas.salida');
        });

        Route::middleware("can:permiso,'autorizados_frecuentes','registrar'")->group(function () {
            Route::get('autorizaciones-frecuentes', [AutorizadoFrecuenteController::class, 'create'])->name('autorizados-frecuentes.create');
            Route::post('autorizaciones-frecuentes', [AutorizadoFrecuenteController::class, 'store'])->name('autorizados-frecuentes.store');
        });

        Route::middleware("can:permiso,'medios_entrada','registrar'")->group(function () {
            Route::get('medios-entrada', [MedioEntradaController::class, 'index'])->name('medios-entrada.index');
            Route::post('medios-entrada', [MedioEntradaController::class, 'store'])->name('medios-entrada.store');
            Route::put('medios-entrada/{medio}', [MedioEntradaController::class, 'update'])->whereNumber('medio')->name('medios-entrada.update');
            Route::delete('medios-entrada/{medio}', [MedioEntradaController::class, 'destroy'])->whereNumber('medio')->name('medios-entrada.destroy');
        });

        Route::middleware("can:permiso,'guardias','ver'")->get('guardias', [GuardiaController::class, 'index'])->name('guardias.index');

        Route::middleware("can:permiso,'guardias','registrar'")->group(function () {
            Route::post('guardias', [GuardiaController::class, 'store'])->name('guardias.store');
            Route::put('guardias/{guardia}', [GuardiaController::class, 'update'])->whereNumber('guardia')->name('guardias.update');
            Route::post('guardias/{guardia}/desactivar', [GuardiaController::class, 'desactivar'])->whereNumber('guardia')->name('guardias.desactivar');
            Route::post('guardias/{guardia}/activar', [GuardiaController::class, 'activar'])->whereNumber('guardia')->name('guardias.activar');
            Route::post('guardias/{guardia}/cuenta', [GuardiaController::class, 'crearCuenta'])->whereNumber('guardia')->name('guardias.cuenta');
        });

        Route::middleware("can:permiso,'turnos','ver'")->get('turnos', [TurnoController::class, 'index'])->name('turnos.index');

        Route::middleware("can:permiso,'turnos','registrar'")->group(function () {
            Route::post('turnos', [TurnoController::class, 'programar'])->name('turnos.programar');
            Route::delete('turnos/{turno}', [TurnoController::class, 'quitar'])->whereNumber('turno')->name('turnos.quitar');
        });

        Route::middleware("can:permiso,'incidencias','ver'")->get('incidencias', [IncidenciaController::class, 'index'])->name('incidencias.index');

        Route::middleware("can:permiso,'tipos_incidencia','registrar'")->group(function () {
            Route::post('tipos-incidencia', [TipoIncidenciaController::class, 'store'])->name('tipos-incidencia.store');
            Route::put('tipos-incidencia/{tipo}', [TipoIncidenciaController::class, 'update'])->whereNumber('tipo')->name('tipos-incidencia.update');
            Route::post('tipos-incidencia/{tipo}/desactivar', [TipoIncidenciaController::class, 'desactivar'])->whereNumber('tipo')->name('tipos-incidencia.desactivar');
            Route::post('tipos-incidencia/{tipo}/activar', [TipoIncidenciaController::class, 'activar'])->whereNumber('tipo')->name('tipos-incidencia.activar');
        });

        // El guardia registra incidencias; además del permiso, exige una cuenta vinculada a un guardia activo
        Route::middleware("can:permiso,'incidencias','registrar'")->group(function () {
            Route::get('mis-incidencias', [IncidenciaController::class, 'misIncidencias'])->name('incidencias.mias');
            Route::post('mis-incidencias', [IncidenciaController::class, 'store'])->name('incidencias.store');
        });

        // Quien ve las incidencias o el guardia que la registró ve la foto
        Route::get('incidencias/{incidencia}/foto', [IncidenciaController::class, 'foto'])->whereNumber('incidencia')->name('incidencias.foto');

        // Cada guardia ve, inicia y entrega sus propios turnos; exige una cuenta vinculada a un guardia activo
        Route::middleware("can:permiso,'entrega_turnos','registrar'")->group(function () {
            Route::get('mis-turnos', [TurnoController::class, 'misTurnos'])->name('turnos.mios');
            Route::post('mis-turnos/{turno}/iniciar', [EntregaTurnoController::class, 'iniciar'])->whereNumber('turno')->name('turnos.iniciar');
            Route::post('mis-turnos/{turno}/entregar', [EntregaTurnoController::class, 'entregar'])->whereNumber('turno')->name('turnos.entregar');
            Route::post('entregas/{entrega}/recibir', [EntregaTurnoController::class, 'recibir'])->whereNumber('entrega')->name('entregas.recibir');
        });
    });
