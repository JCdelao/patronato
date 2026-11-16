<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $residencial_id
 * @property string $tipo
 * @property string $nombre
 * @property bool $activa
 */
#[Fillable(['residencial_id', 'tipo', 'nombre', 'activa'])]
class CategoriaFinanciera extends Model
{
    use PerteneceAResidencial;

    public const INGRESO = 'ingreso';

    public const EGRESO = 'egreso';

    /**
     * @var string
     */
    protected $table = 'categorias_financieras';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }
}
