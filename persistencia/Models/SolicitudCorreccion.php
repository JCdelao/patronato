<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Solicitud de un residente para que la Junta Directiva corrija un dato (HU-54)
 *
 * La solicitud no cambia ningún dato: quien la atiende corrige en el módulo que corresponde
 * y luego la cierra con una respuesta.
 *
 * @property int $id
 * @property int $residencial_id
 * @property int $residente_id
 * @property int|null $vivienda_id
 * @property string $tipo
 * @property string $descripcion
 * @property string|null $adjunto_path
 * @property string $estado
 * @property string|null $respuesta
 * @property int|null $atendida_por
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Residente $residente
 * @property-read Vivienda|null $vivienda
 * @property-read User|null $atendidaPor
 */
#[Fillable(['residencial_id', 'residente_id', 'vivienda_id', 'tipo', 'descripcion', 'adjunto_path', 'estado', 'respuesta', 'atendida_por'])]
class SolicitudCorreccion extends Model
{
    use PerteneceAResidencial;

    public const RECIBIDA = 'recibida';

    public const EN_REVISION = 'en_revision';

    public const RESUELTA = 'resuelta';

    public const RECHAZADA = 'rechazada';

    /**
     * Estados en los que la Junta todavía debe atenderla
     */
    public const ABIERTAS = [self::RECIBIDA, self::EN_REVISION];

    /**
     * Qué pide corregir el residente
     */
    public const TIPOS = [
        'datos_personales' => 'Mis datos personales',
        'residentes' => 'Residentes de la vivienda',
        'vehiculo' => 'Vehículo o sticker',
        'carnet' => 'Carnet',
        'pago' => 'Pago que no aparece',
        'otro' => 'Otro',
    ];

    /**
     * @var string
     */
    protected $table = 'solicitudes_correccion';

    public function abierta(): bool
    {
        return in_array($this->estado, self::ABIERTAS, true);
    }

    /**
     * @return BelongsTo<Residente, $this>
     */
    public function residente(): BelongsTo
    {
        return $this->belongsTo(Residente::class);
    }

    /**
     * @return BelongsTo<Vivienda, $this>
     */
    public function vivienda(): BelongsTo
    {
        return $this->belongsTo(Vivienda::class);
    }

    /**
     * Quien la tomó en revisión o la cerró por última vez
     *
     * @return BelongsTo<User, $this>
     */
    public function atendidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendida_por');
    }
}
