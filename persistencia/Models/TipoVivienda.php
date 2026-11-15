<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de vivienda de un residencial, como casa, apartamento o habitación
 *
 * @property int $id
 * @property int $residencial_id
 * @property string $nombre
 * @property bool $activo
 */
#[Fillable(['residencial_id', 'nombre', 'activo'])]
class TipoVivienda extends Model
{
    use PerteneceAResidencial;

    /**
     * @var string
     */
    protected $table = 'tipos_vivienda';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Vivienda, $this>
     */
    public function viviendas(): HasMany
    {
        return $this->hasMany(Vivienda::class);
    }
}
