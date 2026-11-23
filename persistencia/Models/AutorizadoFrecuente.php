<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $nombre
 * @property string $tipo
 * @property list<int> $dias_semana
 * @property string $hora_inicio
 * @property string $hora_fin
 * @property bool $activo
 * @property-read Collection<int, Vivienda> $viviendas
 */
#[Fillable(['residencial_id', 'nombre', 'tipo', 'dias_semana', 'hora_inicio', 'hora_fin', 'activo', 'created_by'])]
class AutorizadoFrecuente extends Model
{
    use PerteneceAResidencial;

    protected $table = 'autorizados_frecuentes';

    protected function casts(): array
    {
        return ['dias_semana' => 'array', 'activo' => 'boolean'];
    }

    /** @return BelongsToMany<Vivienda, $this> */
    public function viviendas(): BelongsToMany
    {
        return $this->belongsToMany(Vivienda::class, 'autorizado_frecuente_viviendas', 'autorizado_id', 'vivienda_id')
            ->withPivot('residencial_id');
    }
}
