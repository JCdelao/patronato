<?php

namespace Patronato\Presentacion\Controllers;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Patronato\Negocio\Services\CuentaService;
use Patronato\Presentacion\Requests\ProfileDeleteRequest;
use Patronato\Presentacion\Requests\ProfileUpdateRequest;

class ProfileController extends Controller
{
    /**
     * Muestra los ajustes del perfil
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Actualiza el perfil
     */
    public function update(ProfileUpdateRequest $request, CuentaService $cuentas): RedirectResponse
    {
        $cuentas->actualizarPerfil($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Elimina el perfil
     */
    public function destroy(ProfileDeleteRequest $request, CuentaService $cuentas): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $cuentas->eliminar($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
