<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Patronato\Persistencia\Repositorios\AuditoriaRepositorio;

/**
 * Aísla un modelo por residencial
 */
trait PerteneceAResidencial
{
    public static function bootPerteneceAResidencial(): void
    {
        static::addGlobalScope('residencial', function (Builder $query): void {
            $usuario = Auth::user();

            if ($usuario instanceof User && ! $usuario->esAdminPlataforma()) {
                $query->where($query->qualifyColumn('residencial_id'), $usuario->residencial_id);
            }
        });

        static::creating(function (Model $modelo): void {
            if ($modelo->getAttribute('residencial_id') === null && Auth::user() instanceof User) {
                $modelo->setAttribute('residencial_id', Auth::user()->residencial_id);
            }
        });
    }

    /**
     * Busca el modelo de la URL y deniega el de otro residencial
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $modelo = $this->resolveRouteBindingQuery(
            $this->newQuery()->withoutGlobalScope('residencial'),
            $value,
            $field,
        )->first();

        $usuario = Auth::user();

        if ($modelo !== null
            && $usuario instanceof User
            && ! $usuario->esAdminPlataforma()
            && $modelo->getAttribute('residencial_id') !== $usuario->residencial_id) {
            app(AuditoriaRepositorio::class)->registrar($usuario, 'seguridad', 'acceso_denegado', $modelo, [
                'motivo' => 'Intento de acceder a un registro de otro residencial',
            ]);

            abort(403, 'Acceso denegado');
        }

        return $modelo;
    }
}
