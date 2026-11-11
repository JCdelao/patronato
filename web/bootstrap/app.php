<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Patronato\Presentacion\Middleware\BloquearCuentaSinAcceso;
use Patronato\Presentacion\Middleware\CerrarSesionInactiva;
use Patronato\Presentacion\Middleware\ExigirCambioPassword;
use Patronato\Presentacion\Middleware\HandleAppearance;
use Patronato\Presentacion\Middleware\HandleInertiaRequests;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Azure App Service atiende HTTPS y reenvía la petición
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            CerrarSesionInactiva::class,
            BloquearCuentaSinAcceso::class,
            ExigirCambioPassword::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// Las migraciones, seeders y fábricas están en la capa de base de datos
$app->useDatabasePath(dirname(__DIR__, 2).'/base-de-datos');

return $app;
