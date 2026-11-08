<?php

namespace Patronato\Modules\Finanzas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Patronato\Shared\PerteneceAResidencial;

/**
 * @property int $id
 * @property int $residencial_id
 * @property string $modalidad
 * @property list<array{concepto: string, monto: string}>|null $desglose
 * @property Carbon $vigente_desde
 * @property int|null $created_by
 */
#[Fillable(['residencial_id', 'modalidad', 'desglose', 'vigente_desde', 'created_by'])]
class ModalidadCobro extends Model
{
    use PerteneceAResidencial;

    public const UNIFICADA = 'unificada';

    public const DIVERSIFICADA = 'diversificada';

    public const UPDATED_AT = null;

    /**
     * @var string
     */
    protected $table = 'modalidades_cobro';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'desglose' => 'array',
            'vigente_desde' => 'date',
        ];
    }
}
