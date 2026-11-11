<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Patronato\Negocio\Contratos\CargosPendientes;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Negocio\Services\SinCargosPendientes;
use Patronato\Persistencia\Models\Residencial;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Registra los servicios
     */
    public function register(): void
    {
        // Se reemplaza por el servicio real cuando exista la generación de cargos
        $this->app->bind(CargosPendientes::class, SinCargosPendientes::class);
    }

    /**
     * Inicializa los servicios
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Cada residencial nuevo recibe los permisos iniciales de sus roles
        Residencial::created(fn (Residencial $residencial) => app(PermisoService::class)->asignarPredeterminados($residencial->id));

        Gate::define('administrar-plataforma', fn (User $user): bool => $user->esAdminPlataforma());
        Gate::define('administrar-usuarios', fn (User $user): Response => $user->puedeAdministrarUsuarios()
            ? Response::allow()
            : Response::deny('Acceso denegado'));
        Gate::define('configurar-accesos', fn (User $user): Response => $user->residencial_id !== null && $user->rol?->codigo === Rol::ADMIN_TECNICO
            ? Response::allow()
            : Response::deny('Acceso denegado'));

        // Uso en rutas: can:permiso,'modulo','accion'
        Gate::define('permiso', fn (User $user, string $modulo, string $accion): Response => app(PermisoService::class)->puede($user, $modulo, $accion)
            ? Response::allow()
            : Response::deny('Acceso denegado'));
    }

    /**
     * Configura los valores predeterminados de la aplicación
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Política mínima de contraseñas
        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(8)->letters()->numbers()->uncompromised()
            : Password::min(8)->letters()->numbers(),
        );
    }
}
