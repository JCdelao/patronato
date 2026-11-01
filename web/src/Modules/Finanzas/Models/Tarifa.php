<?php

namespace Patronato\Modules\Finanzas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Patronato\Shared\PerteneceAResidencial;

/**
 * @property int $id
 * @property int $residencial_id
 * @property int $concepto_id
 * @property int|null $vivienda_id
 * @property string $monto
 * @property Carbon $vigente_desde
 * @property Carbon|null $vigente_hasta
 * @property string|null $motivo
 */
#[Fillable(['residencial_id', 'concepto_id', 'vivienda_id', 'monto', 'vigente_desde', 'vigente_hasta', 'motivo', 'created_by'])]
class Tarifa extends Model
{
    use PerteneceAResidencial;

    /**
     * @var string
     */
    protected $table = 'tarifas';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }
}
