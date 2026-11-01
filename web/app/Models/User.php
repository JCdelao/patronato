<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Patronato\Modules\Identidad\Models\Rol;
use Patronato\Modules\Identidad\Services\PermisoService;
use Patronato\Modules\Plataforma\Models\Residencial;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $residencial_id
 * @property int|null $rol_id
 * @property bool $activo
 * @property bool $debe_cambiar_password
 * @property-read Rol|null $rol
 * @property-read Residencial|null $residencial
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    public const MENSAJE_RESIDENCIAL_SUSPENDIDO = 'Tu residencial está suspendido. Comunícate con la administración de la plataforma.';

    public const MENSAJE_CUENTA_INACTIVA = 'Tu cuenta está desactivada. Comunícate con la Junta Directiva.';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'activo' => 'boolean',
            'debe_cambiar_password' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Rol, $this>
     */
    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    /**
     * @return BelongsTo<Residencial, $this>
     */
    public function residencial(): BelongsTo
    {
        return $this->belongsTo(Residencial::class);
    }

    /**
     * Determine if the user belongs to a suspended residencial (HU-03).
     */
    public function residencialSuspendido(): bool
    {
        return $this->residencial?->estado === Residencial::SUSPENDIDO;
    }

    /**
     * The reason the user cannot use the system, if any (HU-03, HU-06).
     */
    public function motivoSinAcceso(): ?string
    {
        return match (true) {
            $this->activo === false => self::MENSAJE_CUENTA_INACTIVA,
            $this->residencialSuspendido() => self::MENSAJE_RESIDENCIAL_SUSPENDIDO,
            default => null,
        };
    }

    /**
     * Get the route name of the user's home screen, according to their role (HU-04).
     */
    public function rutaInicio(): string
    {
        return match (true) {
            $this->esAdminPlataforma() => 'plataforma.residenciales.index',
            $this->puede('padron', 'ver') => 'padron.viviendas.index',
            default => 'dashboard',
        };
    }

    /**
     * Determine if the user can manage the accounts of their residencial (HU-06).
     */
    public function puedeAdministrarUsuarios(): bool
    {
        return $this->residencial_id !== null && $this->rol?->codigo === Rol::ADMIN_TECNICO;
    }

    /**
     * Determine if the user administers the whole platform.
     */
    public function esAdminPlataforma(): bool
    {
        return $this->rol?->codigo === Rol::ADMIN_PLATAFORMA;
    }

    /**
     * Determine if the user's role allows an action in a module (HU-07).
     */
    public function puede(string $modulo, string $accion): bool
    {
        return app(PermisoService::class)->puede($this, $modulo, $accion);
    }
}
