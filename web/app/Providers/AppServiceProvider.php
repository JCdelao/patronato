<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('administrar-plataforma', fn (User $user): bool => $user->esAdminPlataforma());
        Gate::define('administrar-usuarios', fn (User $user): Response => $user->puedeAdministrarUsuarios()
            ? Response::allow()
            : Response::deny('Acceso denegado'));

        // Uso en rutas: can:permiso,'modulo','accion' (con comillas, o Laravel los busca como parámetros).
        Gate::define('permiso', fn (User $user, string $modulo, string $accion): Response => $user->puede($modulo, $accion)
            ? Response::allow()
            : Response::deny('Acceso denegado'));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Política mínima de contraseñas (HU-05).
        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(8)->letters()->numbers()->uncompromised()
            : Password::min(8)->letters()->numbers(),
        );
    }
}
