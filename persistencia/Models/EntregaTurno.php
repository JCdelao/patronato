<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fin de un turno con sus novedades; si otro guardia lo recibe, queda pendiente hasta que lo confirma
 *
 * @property int $id
 * @property int $residencial_id
 * @property int $turno_id
 * @property int $guardia_entrega_id
 * @property int|null $guardia_recibe_id
 * @property Carbon $entregado_en
 * @property string|null $novedades
 * @property Carbon|null $recibido_en
 * @property-read Turno $turno
 * @property-read Guardia $guardiaEntrega
 * @property-read Guardia|null $guardiaRecibe
 */
#[Fillable(['residencial_id', 'turno_id', 'guardia_entrega_id', 'guardia_recibe_id', 'entregado_en', 'novedades', 'recibido_en'])]
class EntregaTurno extends Model
{
    use PerteneceAResidencial;

    /**
     * @var string
     */
    protected $table = 'entregas_turno';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entregado_en' => 'datetime',
            'recibido_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Turno, $this>
     */
    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    /**
     * @return BelongsTo<Guardia, $this>
     */
    public function guardiaEntrega(): BelongsTo
    {
        return $this->belongsTo(Guardia::class, 'guardia_entrega_id');
    }

    /**
     * @return BelongsTo<Guardia, $this>
     */
    public function guardiaRecibe(): BelongsTo
    {
        return $this->belongsTo(Guardia::class, 'guardia_recibe_id');
    }
}
