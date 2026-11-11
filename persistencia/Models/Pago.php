<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $residencial_id
 * @property int $vivienda_id
 * @property string $forma_pago
 * @property string $estado
 * @property string $origen
 * @property string $monto
 * @property Carbon $fecha_pago
 * @property string|null $numero_recibo
 * @property int|null $caja_id
 * @property int|null $registrado_por
 * @property Carbon $created_at
 */
#[Fillable(['residencial_id', 'vivienda_id', 'forma_pago', 'estado', 'origen', 'monto', 'fecha_pago', 'numero_recibo', 'caja_id', 'registrado_por'])]
class Pago extends Model
{
    use PerteneceAResidencial;

    public const EFECTIVO = 'efectivo';

    public const CONFIRMADO = 'confirmado';

    /**
     * @var string
     */
    protected $table = 'pagos';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_pago' => 'date',
        ];
    }

    /**
     * Partes del pago aplicadas a cada cargo
     *
     * @return HasMany<PagoAplicacion, $this>
     */
    public function aplicaciones(): HasMany
    {
        return $this->hasMany(PagoAplicacion::class);
    }
}
