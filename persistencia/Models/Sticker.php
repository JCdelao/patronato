<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sticker numerado que identifica a un vehículo autorizado
 *
 * @property int $id
 * @property int $residencial_id
 * @property int $vehiculo_id
 * @property string $numero
 * @property string $estado
 * @property Carbon $fecha_emision
 * @property string|null $motivo
 * @property Carbon $created_at
 * @property-read Vehiculo $vehiculo
 */
#[Fillable(['residencial_id', 'vehiculo_id', 'numero', 'estado', 'fecha_emision', 'motivo'])]
class Sticker extends Model
{
    use PerteneceAResidencial;

    public const ACTIVO = 'activo';

    public const VENCIDO = 'vencido';

    public const SUSPENDIDO = 'suspendido';

    public const REEMPLAZADO = 'reemplazado';

    public const DADO_DE_BAJA = 'dado_de_baja';

    /**
     * Estados en los que el sticker todavía pertenece al vehículo
     */
    public const VIGENTES = [self::ACTIVO, self::VENCIDO, self::SUSPENDIDO];

    /**
     * @var string
     */
    protected $table = 'stickers';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Vehiculo, $this>
     */
    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }
}
