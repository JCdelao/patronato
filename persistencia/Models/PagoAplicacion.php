<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $residencial_id
 * @property int $pago_id
 * @property int $cargo_id
 * @property string $monto
 * @property string $origen
 * @property-read Cargo $cargo
 */
#[Fillable(['residencial_id', 'pago_id', 'cargo_id', 'monto', 'origen'])]
class PagoAplicacion extends Model
{
    use PerteneceAResidencial;

    // La tabla solo tiene created_at
    public const UPDATED_AT = null;

    /**
     * Aplicado al registrar o confirmar el pago; es lo que muestra el comprobante
     */
    public const PAGO = 'pago';

    /**
     * Aplicado después, con el saldo a favor que dejó el pago
     */
    public const SALDO_A_FAVOR = 'saldo_a_favor';

    /**
     * Indica si se aplicó al registrar o confirmar el pago
     */
    public function esDelPago(): bool
    {
        return $this->origen === self::PAGO;
    }

    /**
     * @var string
     */
    protected $table = 'pago_aplicaciones';

    /**
     * Igual que el valor predeterminado de la columna
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'origen' => self::PAGO,
    ];

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

    /**
     * @return BelongsTo<Cargo, $this>
     */
    public function cargo(): BelongsTo
    {
        return $this->belongsTo(Cargo::class);
    }
}
