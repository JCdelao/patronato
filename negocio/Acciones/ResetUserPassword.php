<?php

namespace Patronato\Negocio\Acciones;

use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Patronato\Negocio\Reglas\PasswordValidationRules;
use Patronato\Negocio\Services\BloqueoLoginService;
use Patronato\Negocio\Services\CuentaService;
use Patronato\Persistencia\Models\User;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(
        private CuentaService $cuentas,
        private BloqueoLoginService $bloqueos,
    ) {}

    /**
     * Restablece la contraseña olvidada
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $this->cuentas->cambiarPassword($user, $input['password']);

        $this->bloqueos->limpiar($user->email);
    }
}
