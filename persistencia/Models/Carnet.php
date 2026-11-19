<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Carnet de un residente con su número único y su vigencia
 *
 * @property int $id
 * @property int $residencial_id
 * @property int $residente_id
 * @property string $numero
 * @property Carbon $fecha_emision
 * @property Carbon|null $vigente_hasta
 * @property string $estado
 * @property string|null $motivo
 * @property-read Residente $residente
 */
#[Fillable(['residencial_id', 'residente_id', 'numero', 'fecha_emision', 'vigente_hasta', 'estado', 'motivo'])]
class Carnet extends Model
{
    use PerteneceAResidencial;

    public const ACTIVO = 'activo';

    public const VENCIDO = 'vencido';

    public const SUSPENDIDO = 'suspendido';

    public const REEMPLAZADO = 'reemplazado';

    public const DADO_DE_BAJA = 'dado_de_baja';

    /**
     * Estados en los que el carnet todavía pertenece al residente
     */
    public const VIGENTES = [self::ACTIVO, self::VENCIDO, self::SUSPENDIDO];

    /**
     * @var string
     */
    protected $table = 'carnets';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Residente, $this>
     */
    public function residente(): BelongsTo
    {
        return $this->belongsTo(Residente::class);
    }
}
