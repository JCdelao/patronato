<?php

namespace Patronato\Persistencia\Repositorios;

use Illuminate\Database\Eloquent\Collection;
use Patronato\Persistencia\Models\Rol;
use Patronato\Persistencia\Models\User;

class UsuarioRepositorio extends Repositorio
{
    public function buscar(int $id): User
    {
        return User::query()->findOrFail($id);
    }

    public function porCorreo(string $correo): ?User
    {
        return User::query()->where('email', $correo)->first();
    }

    /**
     * Cuentas del residencial con su rol, por nombre
     *
     * @return Collection<int, User>
     */
    public function delResidencial(int $residencialId): Collection
    {
        return User::query()
            ->where('residencial_id', $residencialId)
            ->with('rol')
            ->orderBy('name')
            ->get();
    }

    /**
     * Id y nombre de las cuentas del residencial
     *
     * @return list<array{id: int, name: string}>
     */
    public function opciones(int $residencialId): array
    {
        return array_values(User::query()
            ->where('residencial_id', $residencialId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $usuario) => ['id' => $usuario->id, 'name' => $usuario->name])
            ->all());
    }

    /**
     * Cuentas activas con alguno de los roles
     *
     * @param  array<int, string>  $codigos
     * @return Collection<int, User>
     */
    public function activasConRoles(array $codigos): Collection
    {
        return User::query()
            ->where('activo', true)
            ->whereIn('rol_id', Rol::query()->whereIn('codigo', $codigos)->select('id'))
            ->get();
    }

    /**
     * Crea una cuenta; permite datos que no se asignan en masa
     *
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): User
    {
        $usuario = new User;
        $usuario->forceFill($datos)->save();

        return $usuario;
    }

    /**
     * Guarda datos de una cuenta, incluso los que no se asignan en masa
     *
     * @param  array<string, mixed>  $datos
     */
    public function guardar(User $usuario, array $datos): void
    {
        $usuario->forceFill($datos)->save();
    }
}
