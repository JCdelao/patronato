<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Turno programado de un guardia; los turnos de un guardia no se superponen
 *
 * inicio_real es cuando el guardia lo inició; al irse lo entrega a otro guardia.
 *
 * @property int $id
 * @property int $residencial_id
 * @property int $guardia_id
 * @property string $tipo
 * @property Carbon $inicio
 * @property Carbon $fin
 * @property Carbon|null $inicio_real
 * @property-read Guardia $guardia
 * @property-read EntregaTurno|null $entrega
 */
#[Fillable(['residencial_id', 'guardia_id', 'tipo', 'inicio', 'fin', 'inicio_real'])]
class Turno extends Model
{
    use PerteneceAResidencial;

    public const TIPOS = ['dia', 'tarde', 'noche', '24_horas'];

    /**
     * Hora de inicio y horas sugeridas de cada tipo; al programar se ajustan a cada caso
     */
    public const SUGERIDOS = [
        'dia' => ['hora' => '06:00', 'horas' => 8],
        'tarde' => ['hora' => '14:00', 'horas' => 8],
        'noche' => ['hora' => '22:00', 'horas' => 8],
        '24_horas' => ['hora' => '07:00', 'horas' => 24],
    ];

    /**
     * Horas que puede durar un turno
     */
    public const MAXIMO_HORAS = 24;

    /**
     * @var string
     */
    protected $table = 'turnos';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inicio' => 'datetime',
            'fin' => 'datetime',
            'inicio_real' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Guardia, $this>
     */
    public function guardia(): BelongsTo
    {
        return $this->belongsTo(Guardia::class);
    }

    /**
     * @return HasOne<EntregaTurno, $this>
     */
    public function entrega(): HasOne
    {
        return $this->hasOne(EntregaTurno::class);
    }
}
