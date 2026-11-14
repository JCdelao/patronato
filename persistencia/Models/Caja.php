<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $residencial_id
 * @property int $cobrador_id
 * @property string $estado
 * @property Carbon $abierta_en
 * @property Carbon|null $cerrada_en
 * @property int|null $cerrada_por
 * @property string|null $total_registrado
 * @property string|null $monto_entregado
 * @property string|null $diferencia
 * @property string|null $justificacion
 * @property-read User $cobrador
 * @property-read User|null $cerradaPor
 * @property-read Collection<int, Pago> $pagos
 */
#[Fillable(['residencial_id', 'cobrador_id', 'estado', 'abierta_en', 'cerrada_en', 'cerrada_por', 'total_registrado', 'monto_entregado', 'justificacion'])]
class Caja extends Model
{
    use PerteneceAResidencial;

    public const ABIERTA = 'abierta';

    public const CERRADA = 'cerrada';

    /**
     * @var string
     */
    protected $table = 'cajas';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abierta_en' => 'datetime',
            'cerrada_en' => 'datetime',
            'total_registrado' => 'decimal:2',
            'monto_entregado' => 'decimal:2',
            'diferencia' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cobrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cobrador_id');
    }

    /**
     * Usuario que hizo el cierre
     *
     * @return BelongsTo<User, $this>
     */
    public function cerradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
    }

    /**
     * Cobros registrados en la caja
     *
     * @return HasMany<Pago, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }
}
