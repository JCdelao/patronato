<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Patronato\Negocio\Services\CuentaService;
use Patronato\Persistencia\Models\User;
use Patronato\Presentacion\Controllers\AuditoriaController;
use Patronato\Presentacion\Controllers\CuentaController;
use Patronato\Presentacion\Controllers\PermisoController;

Route::bind('cuenta', function (string $valor): User {
    $actor = Auth::user();

    abort_unless($actor instanceof User, 403);

    return app(CuentaService::class)->resolverDelResidencial((int) $valor, $actor);
});

Route::middleware(['auth', 'verified', 'can:administrar-usuarios'])
    ->prefix('usuarios')
    ->name('usuarios.')
    ->group(function () {
        Route::get('/', [CuentaController::class, 'index'])->name('index');
        Route::post('/', [CuentaController::class, 'store'])->name('store');
        Route::middleware('can:configurar-accesos')->group(function () {
            Route::put('parametros', [CuentaController::class, 'parametros'])->name('parametros');
            Route::get('permisos', [PermisoController::class, 'index'])->name('permisos.index');
            Route::put('permisos/{rol}', [PermisoController::class, 'update'])->whereNumber('rol')->name('permisos.update');
        });
        Route::put('{cuenta}', [CuentaController::class, 'update'])->whereNumber('cuenta')->name('update');
        Route::post('{cuenta}/desactivar', [CuentaController::class, 'desactivar'])->whereNumber('cuenta')->name('desactivar');
        Route::post('{cuenta}/activar', [CuentaController::class, 'activar'])->whereNumber('cuenta')->name('activar');
    });

Route::get('auditoria', [AuditoriaController::class, 'index'])
    ->middleware(['auth', 'verified', "can:permiso,'auditoria','ver'"])
    ->name('auditoria.index');
