<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Solicitud para anular un pago; la aprueba o la rechaza un segundo directivo
 *
 * @property int $id
 * @property int $residencial_id
 * @property int $pago_id
 * @property int $solicitada_por
 * @property string $motivo
 * @property string $estado
 * @property int|null $resuelta_por
 * @property Carbon|null $resuelta_en
 * @property string|null $motivo_rechazo
 * @property Carbon $created_at
 * @property-read Pago $pago
 * @property-read User $solicitante
 * @property-read User|null $resolutor
 */
#[Fillable(['residencial_id', 'pago_id', 'solicitada_por', 'motivo', 'estado', 'resuelta_por', 'resuelta_en', 'motivo_rechazo'])]
class SolicitudAnulacion extends Model
{
    use PerteneceAResidencial;

    public const PENDIENTE = 'pendiente';

    public const APROBADA = 'aprobada';

    public const RECHAZADA = 'rechazada';

    /**
     * @var string
     */
    protected $table = 'solicitudes_anulacion';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resuelta_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Pago, $this>
     */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }

    /**
     * Directivo que pidió la anulación
     *
     * @return BelongsTo<User, $this>
     */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitada_por');
    }

    /**
     * Directivo que aprobó o rechazó la solicitud
     *
     * @return BelongsTo<User, $this>
     */
    public function resolutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelta_por');
    }
}
