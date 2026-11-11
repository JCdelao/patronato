<?php

namespace Patronato\Negocio\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Patronato\Negocio\Notificaciones\CuentaCreada;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;
use Patronato\Persistencia\Repositorios\RolRepositorio;
use Patronato\Persistencia\Repositorios\UsuarioRepositorio;
use Patronato\Persistencia\Transaccion;
use Throwable;

class CuentaService
{
    public function __construct(
        private UsuarioRepositorio $usuarios,
        private RolRepositorio $roles,
        private Transaccion $transaccion,
        private AuditoriaService $auditoria,
        private ResidenteService $residentes,
    ) {}

    public function porCorreo(string $correo): ?User
    {
        return $this->usuarios->porCorreo($correo);
    }

    /**
     * Cuentas del residencial con su rol
     *
     * @return Collection<int, User>
     */
    public function delResidencial(int $residencialId): Collection
    {
        return $this->usuarios->delResidencial($residencialId);
    }

    /**
     * Id y nombre de las cuentas del residencial
     *
     * @return list<array{id: int, name: string}>
     */
    public function opcionesDelResidencial(int $residencialId): array
    {
        return $this->usuarios->opciones($residencialId);
    }

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
            $this->roles->porCodigo(Rol::ADMIN_TECNICO)->id,
        );
    }

    /**
     * Roles que el administrador técnico puede asignar
     *
     * @return Collection<int, Rol>
     */
    public function rolesAsignables(): Collection
    {
        return $this->roles->excepto([Rol::ADMIN_PLATAFORMA, Rol::ADMIN_TECNICO]);
    }

    /**
     * Indica si el rol es el de residente
     */
    public function esRolResidente(int $rolId): bool
    {
        return $this->roles->codigoDe($rolId) === Rol::RESIDENTE;
    }

    /**
     * Crea una cuenta del residencial y le envía la contraseña temporal
     *
     * @param  array{name: string, email: string, rol_id: int|string, residente_id?: int|string|null}  $datos
     * @return array{usuario: User, password_temporal: string, correo_enviado: bool}
     */
    public function crear(User $actor, array $datos): array
    {
        $cuenta = $this->transaccion->ejecutar(function () use ($actor, $datos) {
            $cuenta = $this->crearConPasswordTemporal((int) $actor->residencial_id, $datos['name'], $datos['email'], (int) $datos['rol_id']);

            $this->residentes->vincularCuenta($cuenta['usuario']->id, $this->residenteDe($datos));

            $this->auditoria->registrar($actor, 'identidad', 'cuenta_creada', $cuenta['usuario'], [
                'valor_nuevo' => ['email' => $datos['email'], 'rol_id' => (int) $datos['rol_id']],
            ]);

            return $cuenta;
        });

        return [...$cuenta, 'correo_enviado' => $this->enviarPasswordTemporal($cuenta['usuario'], $cuenta['password_temporal'])];
    }

    /**
     * Envía la contraseña temporal al correo de la cuenta
     */
    public function enviarPasswordTemporal(User $usuario, string $passwordTemporal): bool
    {
        // Con el correo en modo log no le llega nada a la persona
        if (config('mail.default') === 'log') {
            return false;
        }

        try {
            $usuario->notify(new CuentaCreada($passwordTemporal, (string) $usuario->rol?->nombre));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * @param  array{name: string, email: string, rol_id: int|string, residente_id?: int|string|null}  $datos
     */
    public function actualizar(User $actor, User $cuenta, array $datos): void
    {
        $this->transaccion->ejecutar(function () use ($actor, $cuenta, $datos) {
            $anterior = ['email' => $cuenta->email, 'rol_id' => $cuenta->rol_id];

            $this->usuarios->guardar($cuenta, [
                'name' => $datos['name'],
                'email' => $datos['email'],
                'rol_id' => (int) $datos['rol_id'],
            ]);

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
        $this->transaccion->ejecutar(function () use ($actor, $cuenta, $activo) {
            $this->usuarios->guardar($cuenta, ['activo' => $activo]);

            $this->auditoria->registrar($actor, 'identidad', $activo ? 'cuenta_activada' : 'cuenta_desactivada', $cuenta);
        });
    }

    /**
     * Reemplaza la contraseña temporal por la que eligió el usuario
     */
    public function cambiarPassword(User $usuario, string $password): void
    {
        $this->usuarios->guardar($usuario, [
            'password' => $password,
            'debe_cambiar_password' => false,
        ]);
    }

    /**
     * Cambia la contraseña desde la configuración de la cuenta
     */
    public function actualizarPassword(User $usuario, string $password): void
    {
        $this->usuarios->guardar($usuario, ['password' => $password]);
    }

    /**
     * Actualiza el nombre y el correo; un correo nuevo debe verificarse otra vez
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizarPerfil(User $usuario, array $datos): void
    {
        $usuario->fill($datos);

        if ($usuario->isDirty('email')) {
            $usuario->email_verified_at = null;
        }

        $this->usuarios->guardar($usuario, []);
    }

    /**
     * Elimina la cuenta del propio usuario
     */
    public function eliminar(User $usuario): void
    {
        $this->usuarios->eliminar($usuario);
    }

    /**
     * Busca la cuenta indicada en la URL
     */
    public function resolverDelResidencial(int $id, User $actor): User
    {
        $cuenta = $this->usuarios->buscar($id);

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
        $this->usuarios->guardar($usuario, [
            'email_verified_at' => $usuario->email_verified_at ?? now(),
            'residencial_id' => null,
            'rol_id' => $this->roles->porCodigo(Rol::ADMIN_PLATAFORMA)->id,
        ]);
    }

    /**
     * @return array{usuario: User, password_temporal: string}
     */
    private function crearConPasswordTemporal(int $residencialId, string $nombre, string $correo, int $rolId): array
    {
        $passwordTemporal = Str::password(16);

        // Marca el correo como verificado
        $usuario = $this->usuarios->crear([
            'name' => $nombre,
            'email' => $correo,
            'password' => $passwordTemporal,
            'email_verified_at' => now(),
            'residencial_id' => $residencialId,
            'rol_id' => $rolId,
            'debe_cambiar_password' => true,
        ]);

        return ['usuario' => $usuario, 'password_temporal' => $passwordTemporal];
    }

    /**
     * @param  array{rol_id: int|string, residente_id?: int|string|null}  $datos
     */
    private function residenteDe(array $datos): ?int
    {
        return $this->esRolResidente((int) $datos['rol_id']) && ! empty($datos['residente_id']) ? (int) $datos['residente_id'] : null;
    }
}
