<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $residencial_id
 * @property int $categoria_id
 * @property Carbon $fecha
 * @property string $concepto
 * @property string|null $proveedor
 * @property string $monto
 * @property string|null $forma_pago
 * @property string $numero_comprobante
 * @property string|null $factura_path
 * @property string $estado
 * @property string|null $motivo_anulacion
 * @property int|null $registrado_por
 * @property Carbon $created_at
 * @property-read CategoriaFinanciera $categoria
 * @property-read User|null $registrador
 */
#[Fillable(['residencial_id', 'categoria_id', 'fecha', 'concepto', 'proveedor', 'monto', 'forma_pago', 'numero_comprobante', 'factura_path', 'estado', 'motivo_anulacion', 'registrado_por'])]
class Egreso extends Model
{
    use PerteneceAResidencial;

    public const VIGENTE = 'vigente';

    public const ANULADO = 'anulado';

    public const FORMAS_PAGO = ['efectivo', 'transferencia', 'cheque'];

    /**
     * @var string
     */
    protected $table = 'egresos';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha' => 'date',
        ];
    }

    /**
     * @return BelongsTo<CategoriaFinanciera, $this>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaFinanciera::class, 'categoria_id');
    }

    /**
     * Usuario que registró el egreso
     *
     * @return BelongsTo<User, $this>
     */
    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
