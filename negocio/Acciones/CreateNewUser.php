<?php

namespace Patronato\Negocio\Acciones;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Patronato\Negocio\Reglas\PasswordValidationRules;
use Patronato\Negocio\Reglas\ProfileValidationRules;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\UsuarioRepositorio;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private UsuarioRepositorio $usuarios) {}

    /**
     * Valida y crea un usuario registrado
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            // Pide la contraseña una sola vez
            'password' => ['required', 'string', Password::default()],
        ])->validate();

        return $this->usuarios->crear([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
