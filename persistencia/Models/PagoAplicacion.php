<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $residencial_id
 * @property int $pago_id
 * @property int $cargo_id
 * @property string $monto
 */
#[Fillable(['residencial_id', 'pago_id', 'cargo_id', 'monto'])]
class PagoAplicacion extends Model
{
    use PerteneceAResidencial;

    // La tabla solo tiene created_at
    public const UPDATED_AT = null;

    /**
     * @var string
     */
    protected $table = 'pago_aplicaciones';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
        ];
    }
}
