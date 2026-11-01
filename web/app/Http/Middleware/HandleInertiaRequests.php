<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Patronato\Modules\Identidad\Services\PermisoService;

class HandleInertiaRequests extends Middleware
{
    /**
     * Plantilla raíz de la aplicación
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Versión actual de los archivos del frontend
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Datos compartidos con todas las páginas
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'esAdminPlataforma' => $request->user()?->esAdminPlataforma() ?? false,
                'permisos' => $request->user() ? app(PermisoService::class)->deUsuario($request->user()) : [],
                'puedeAdministrarUsuarios' => $request->user()?->puedeAdministrarUsuarios() ?? false,
                'rol' => $request->user()?->rol?->nombre,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
