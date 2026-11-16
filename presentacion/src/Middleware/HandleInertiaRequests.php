<?php

namespace Patronato\Presentacion\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Patronato\Negocio\Services\AnulacionPagoService;
use Patronato\Negocio\Services\PermisoService;
use Patronato\Negocio\Services\TransferenciaService;

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
                // Solicitudes de anulación por aprobar, para el número del menú
                'anulacionesPendientes' => fn () => $request->user() ? app(AnulacionPagoService::class)->cantidadPendientes($request->user()) : 0,
                // Solo una cuenta vinculada a un residente activo ve el portal en el menú (HU-25)
                'esResidente' => fn () => $request->user() !== null && app(TransferenciaService::class)->esResidente($request->user()),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
