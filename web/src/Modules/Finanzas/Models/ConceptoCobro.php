<?php

namespace Patronato\Modules\Finanzas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Patronato\Shared\PerteneceAResidencial;

/**
 * @property int $id
 * @property int $residencial_id
 * @property string $nombre
 * @property string $tipo
 * @property bool $es_cuota_unificada
 * @property bool $activo
 */
#[Fillable(['residencial_id', 'nombre', 'tipo', 'es_cuota_unificada'])]
class ConceptoCobro extends Model
{
    use PerteneceAResidencial;

    /**
     * @var string
     */
    protected $table = 'conceptos_cobro';
}
