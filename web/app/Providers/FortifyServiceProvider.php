<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\RespuestaEnlaceRechazado;
use App\Http\Responses\RespuestaInicioSesion;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Patronato\Modules\Identidad\Services\BloqueoLoginService;
use Patronato\Modules\Plataforma\Services\ConfiguracionService;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponse::class, RespuestaInicioSesion::class);
        $this->app->singleton(TwoFactorLoginResponse::class, RespuestaInicioSesion::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, RespuestaEnlaceRechazado::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        Fortify::authenticateUsing(function (Request $request): ?User {
            $correo = $request->string(Fortify::username())->toString();
            $bloqueos = app(BloqueoLoginService::class);

            $minutos = $bloqueos->minutosRestantes($correo);

            if ($minutos !== null) {
                throw ValidationException::withMessages([
                    Fortify::username() => "Demasiados intentos fallidos. Inténtalo de nuevo en {$minutos} minutos.",
                ]);
            }

            $user = User::query()->where(Fortify::username(), $correo)->first();

            if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
                $seguridad = app(ConfiguracionService::class)->seguridad($user?->residencial_id);

                $bloqueos->registrarFallo($correo, $seguridad['max_intentos_login'], $seguridad['minutos_bloqueo_login']);

                return null;
            }

            $bloqueos->limpiar($correo);

            // Solo se avisa con la contraseña correcta, para no revelar datos de la cuenta (HU-03).
            $motivo = $user->motivoSinAcceso();

            if ($motivo !== null) {
                throw ValidationException::withMessages([Fortify::username() => $motivo]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            // Freno general: el bloqueo de cuenta por intentos fallidos lo aplica BloqueoLoginService (HU-04).
            return Limit::perMinute(30)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
