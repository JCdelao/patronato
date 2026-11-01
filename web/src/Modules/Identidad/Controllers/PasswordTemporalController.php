<?php

namespace Patronato\Modules\Identidad\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordTemporalController extends Controller
{
    use PasswordValidationRules;

    /**
     * Muestra el formulario para reemplazar la contraseña temporal.
     */
    public function edit(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->debe_cambiar_password) {
            return to_route('dashboard');
        }

        return Inertia::render('auth/cambiar-password', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Reemplaza la contraseña temporal por la que elige el usuario.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => $this->currentPasswordRules(),
            'password' => [...$this->passwordRules(), 'different:current_password'],
        ], [
            'current_password.current_password' => 'La contraseña temporal no es correcta.',
            'password.different' => 'La nueva contraseña debe ser distinta de la temporal.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
        ]);

        $request->user()->forceFill([
            'password' => $validated['password'],
            'debe_cambiar_password' => false,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contraseña actualizada.']);

        return to_route('dashboard');
    }
}
