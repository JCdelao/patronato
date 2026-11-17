<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Monto que el patronato planea gastar en una categoría de egreso durante un año
 *
 * @property int $id
 * @property int $residencial_id
 * @property int $categoria_id
 * @property int $anio
 * @property string $monto
 * @property-read CategoriaFinanciera $categoria
 */
#[Fillable(['residencial_id', 'categoria_id', 'anio', 'monto'])]
class Presupuesto extends Model
{
    use PerteneceAResidencial;

    /**
     * @var string
     */
    protected $table = 'presupuestos';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'monto' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CategoriaFinanciera, $this>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaFinanciera::class, 'categoria_id');
    }
}
