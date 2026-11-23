<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Patronato\Negocio\Adaptadores\HojaCalculoOpenSpout;
use Patronato\Negocio\Adaptadores\PdfDompdf;
use Patronato\Negocio\Contratos\CargosPendientes;
use Patronato\Negocio\Contratos\GeneradorHojaCalculo;
use Patronato\Negocio\Contratos\GeneradorPdf;
use Patronato\Negocio\Eventos\CargosGenerados;
use Patronato\Negocio\Eventos\PagoAnulado;
use Patronato\Negocio\Eventos\PagoConfirmado;
use Patronato\Negocio\Eventos\ResidenteDadoDeBaja;
use Patronato\Negocio\Eventos\VehiculoDadoDeBaja;
use Patronato\Negocio\Oyentes\AnularIngresoDeCobro;
use Patronato\Negocio\Oyentes\AplicarSaldoAFavor;
use Patronato\Negocio\Oyentes\DarDeBajaCarnetDelResidente;
use Patronato\Negocio\Oyentes\DarDeBajaStickerDelVehiculo;
use Patronato\Negocio\Oyentes\RegistrarIngresoDeCobro;
use Patronato\Negocio\Services\CargosPendientesService;
use Patronato\Negocio\Services\CategoriaFinancieraService;
use Patronato\Negocio\Services\MedioEntradaService;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Negocio\Services\TipoIncidenciaService;
use Patronato\Negocio\Services\TipoViviendaService;
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
        // El cobro conoce los meses pendientes a partir de los cargos generados
        $this->app->bind(CargosPendientes::class, CargosPendientesService::class);

        // Patrón Singleton (creacional): el contenedor crea una sola instancia y la reutiliza
        // Una sola instancia: los adaptadores no guardan estado entre documentos
        $this->app->singleton(GeneradorPdf::class, PdfDompdf::class);
        $this->app->singleton(GeneradorHojaCalculo::class, HojaCalculoOpenSpout::class);
    }

    /**
     * Inicializa los servicios
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Patrón Observer (comportamiento): reacciona cuando se crea un residencial
        // Cada residencial nuevo recibe sus permisos y catálogos iniciales.
        Residencial::created(function (Residencial $residencial) {
            app(PermisoService::class)->asignarPredeterminados($residencial->id);
            app(TipoViviendaService::class)->crearPredeterminados($residencial->id);
            app(CategoriaFinancieraService::class)->crearPredeterminadas($residencial->id);
            app(TipoIncidenciaService::class)->crearPredeterminados($residencial->id);
            app(MedioEntradaService::class)->crearPredeterminados($residencial->id);
        });

        // Patrón Observer (comportamiento): los oyentes reaccionan a los eventos del pago
        // Tesorería escucha a los cobros: cada pago confirmado es un ingreso y su anulación lo anula
        Event::listen(PagoConfirmado::class, RegistrarIngresoDeCobro::class);
        Event::listen(PagoAnulado::class, AnularIngresoDeCobro::class);

        // Cada generación de cargos aplica el saldo a favor que tengan las viviendas
        Event::listen(CargosGenerados::class, AplicarSaldoAFavor::class);

        // Un vehículo dado de baja no conserva su sticker
        Event::listen(VehiculoDadoDeBaja::class, DarDeBajaStickerDelVehiculo::class);

        // Un residente dado de baja no conserva su carnet
        Event::listen(ResidenteDadoDeBaja::class, DarDeBajaCarnetDelResidente::class);

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
        Password::defaults(fn (): Password => Password::min(8)->letters()->numbers());
    }
}
