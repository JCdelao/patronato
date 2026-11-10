<?php

namespace Patronato\Modules\Finanzas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Patronato\Shared\PerteneceAResidencial;

/**
 * @property int $id
 * @property int $residencial_id
 * @property int $vivienda_id
 * @property int $concepto_id
 * @property Carbon $periodo
 * @property string $monto
 */
class Cargo extends Model
{
    use PerteneceAResidencial;

    /**
     * @var string
     */
    protected $table = 'cargos';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'periodo' => 'date',
            'monto' => 'decimal:2',
        ];
    }
}
