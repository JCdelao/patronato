<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de incidencia del catálogo del residencial; el nombre no se repite en el residencial
 *
 * @property int $id
 * @property int $residencial_id
 * @property string $nombre
 * @property bool $activo
 */
#[Fillable(['residencial_id', 'nombre', 'activo'])]
class TipoIncidencia extends Model
{
    use PerteneceAResidencial;

    /**
     * @var string
     */
    protected $table = 'tipos_incidencia';

    /**
     * @var bool
     */
    public $timestamps = false;

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
}
