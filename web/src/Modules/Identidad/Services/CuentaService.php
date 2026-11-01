<?php

namespace Patronato\Modules\Identidad\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Patronato\Modules\Identidad\Models\Rol;
use Patronato\Modules\Padron\Services\ResidenteService;

class CuentaService
{
    public function __construct(
        private AuditoriaService $auditoria,
        private ResidenteService $residentes,
    ) {}

    /**
     * Crea el administrador técnico de un residencial
     *
     * @return array{usuario: User, password_temporal: string}
     */
    public function crearAdministradorTecnico(int $residencialId, string $nombre, string $correo): array
    {
        return $this->crearConPasswordTemporal(
            $residencialId,
            $nombre,
            $correo,
            Rol::query()->where('codigo', Rol::ADMIN_TECNICO)->firstOrFail()->id,
        );
    }

    /**
     * Roles que el administrador técnico puede asignar
     *
     * @return Collection<int, Rol>
     */
    public function rolesAsignables(): Collection
    {
        return Rol::query()
            ->whereNotIn('codigo', [Rol::ADMIN_PLATAFORMA, Rol::ADMIN_TECNICO])
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    /**
     * Crea una cuenta del residencial
     *
     * @param  array{name: string, email: string, rol_id: int|string, residente_id?: int|string|null}  $datos
     * @return array{usuario: User, password_temporal: string}
     */
    public function crear(User $actor, array $datos): array
    {
        return DB::transaction(function () use ($actor, $datos) {
            $cuenta = $this->crearConPasswordTemporal((int) $actor->residencial_id, $datos['name'], $datos['email'], (int) $datos['rol_id']);

            $this->residentes->vincularCuenta($cuenta['usuario']->id, $this->residenteDe($datos));

            $this->auditoria->registrar($actor, 'identidad', 'cuenta_creada', $cuenta['usuario'], [
                'valor_nuevo' => ['email' => $datos['email'], 'rol_id' => (int) $datos['rol_id']],
            ]);

            return $cuenta;
        });
    }

    /**
     * @param  array{name: string, email: string, rol_id: int|string, residente_id?: int|string|null}  $datos
     */
    public function actualizar(User $actor, User $cuenta, array $datos): void
    {
        DB::transaction(function () use ($actor, $cuenta, $datos) {
            $anterior = ['email' => $cuenta->email, 'rol_id' => $cuenta->rol_id];

            $cuenta->forceFill([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'rol_id' => (int) $datos['rol_id'],
            ])->save();

            $this->residentes->vincularCuenta($cuenta->id, $this->residenteDe($datos));

            $this->auditoria->registrar($actor, 'identidad', 'cuenta_editada', $cuenta, [
                'valor_anterior' => $anterior,
                'valor_nuevo' => ['email' => $cuenta->email, 'rol_id' => $cuenta->rol_id],
            ]);
        });
    }

    /**
     * Activa o desactiva una cuenta
     */
    public function cambiarActivo(User $actor, User $cuenta, bool $activo): void
    {
        DB::transaction(function () use ($actor, $cuenta, $activo) {
            $cuenta->forceFill(['activo' => $activo])->save();

            $this->auditoria->registrar($actor, 'identidad', $activo ? 'cuenta_activada' : 'cuenta_desactivada', $cuenta);
        });
    }

    /**
     * Busca la cuenta indicada en la URL
     */
    public function resolverDelResidencial(int $id, User $actor): User
    {
        $cuenta = User::query()->findOrFail($id);

        if ($cuenta->residencial_id !== $actor->residencial_id) {
            $this->auditoria->registrarAccesoDenegado($actor, $cuenta);

            abort(403, 'Acceso denegado');
        }

        return $cuenta;
    }

    /**
     * Convierte una cuenta existente en administrador de la plataforma
     */
    public function asignarAdministradorPlataforma(User $usuario): void
    {
        $usuario->forceFill([
            'email_verified_at' => $usuario->email_verified_at ?? now(),
            'residencial_id' => null,
            'rol_id' => Rol::query()->where('codigo', Rol::ADMIN_PLATAFORMA)->firstOrFail()->id,
        ])->save();
    }

    /**
     * @return array{usuario: User, password_temporal: string}
     */
    private function crearConPasswordTemporal(int $residencialId, string $nombre, string $correo, int $rolId): array
    {
        $passwordTemporal = Str::password(16);

        $usuario = new User;

        // Marca el correo como verificado
        $usuario->forceFill([
            'name' => $nombre,
            'email' => $correo,
            'password' => $passwordTemporal,
            'email_verified_at' => now(),
            'residencial_id' => $residencialId,
            'rol_id' => $rolId,
            'debe_cambiar_password' => true,
        ])->save();

        return ['usuario' => $usuario, 'password_temporal' => $passwordTemporal];
    }

    /**
     * @param  array{rol_id: int|string, residente_id?: int|string|null}  $datos
     */
    private function residenteDe(array $datos): ?int
    {
        $esResidente = Rol::query()->whereKey($datos['rol_id'])->value('codigo') === Rol::RESIDENTE;

        return $esResidente && ! empty($datos['residente_id']) ? (int) $datos['residente_id'] : null;
    }
}
