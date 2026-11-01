<?php

namespace Patronato\Shared;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Patronato\Modules\Identidad\Services\AuditoriaService;

/**
 * Aísla un modelo por residencial (HU-02): sus consultas solo ven los registros
 * del residencial del usuario, y abrir uno ajeno por URL se deniega y se audita.
 *
 * Sin usuario autenticado (comandos y trabajos en cola) no se filtra: ahí el
 * residencial debe indicarse de forma explícita.
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
     * Retrieve the model for a bound value.
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
            app(AuditoriaService::class)->registrarAccesoDenegado($usuario, $modelo);

            abort(403, 'Acceso denegado');
        }

        return $modelo;
    }
}
