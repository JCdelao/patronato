<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persona autorizada a conducir un vehículo
 *
 * @property int $id
 * @property int $residencial_id
 * @property int $vehiculo_id
 * @property int|null $residente_id
 * @property string|null $nombre
 * @property-read Residente|null $residente
 */
#[Fillable(['residencial_id', 'vehiculo_id', 'residente_id', 'nombre'])]
class VehiculoConductor extends Model
{
    use PerteneceAResidencial;

    /**
     * La tabla solo guarda la fecha de creación
     */
    public const UPDATED_AT = null;

    /**
     * @var string
     */
    protected $table = 'vehiculo_conductores';

    /**
     * @return BelongsTo<Residente, $this>
     */
    public function residente(): BelongsTo
    {
        return $this->belongsTo(Residente::class);
    }
}
