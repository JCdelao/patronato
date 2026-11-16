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
 * @property int|null $pago_id
 * @property Carbon $fecha
 * @property string $concepto
 * @property string $monto
 * @property string|null $numero_comprobante
 * @property string $estado
 * @property string|null $motivo_anulacion
 * @property int|null $registrado_por
 * @property Carbon $created_at
 * @property-read CategoriaFinanciera $categoria
 * @property-read Pago|null $pago
 * @property-read User|null $registrador
 */
#[Fillable(['residencial_id', 'categoria_id', 'pago_id', 'fecha', 'concepto', 'monto', 'numero_comprobante', 'estado', 'motivo_anulacion', 'registrado_por'])]
class Ingreso extends Model
{
    use PerteneceAResidencial;

    public const VIGENTE = 'vigente';

    public const ANULADO = 'anulado';

    /**
     * @var string
     */
    protected $table = 'ingresos';

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
     * Un ingreso con pago lo creó el sistema al confirmar un cobro
     */
    public function esDeCobro(): bool
    {
        return $this->pago_id !== null;
    }

    /**
     * @return BelongsTo<CategoriaFinanciera, $this>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaFinanciera::class, 'categoria_id');
    }

    /**
     * Cobro que originó el ingreso, si viene de uno
     *
     * @return BelongsTo<Pago, $this>
     */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    /**
     * Usuario que registró el ingreso
     *
     * @return BelongsTo<User, $this>
     */
    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
