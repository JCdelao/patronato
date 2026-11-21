<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Incidencia que registra un guardia, con foto opcional; la urgente se avisa por correo
 *
 * @property int $id
 * @property int $residencial_id
 * @property int $tipo_incidencia_id
 * @property int $guardia_id
 * @property int|null $turno_id
 * @property string $descripcion
 * @property Carbon $ocurrida_en
 * @property string|null $foto_path
 * @property bool $urgente
 * @property Carbon $created_at
 * @property-read TipoIncidencia $tipo
 * @property-read Guardia $guardia
 */
#[Fillable(['residencial_id', 'tipo_incidencia_id', 'guardia_id', 'turno_id', 'descripcion', 'ocurrida_en', 'foto_path', 'urgente'])]
class Incidencia extends Model
{
    use PerteneceAResidencial;

    /**
     * @var string
     */
    protected $table = 'incidencias';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ocurrida_en' => 'datetime',
            'urgente' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TipoIncidencia, $this>
     */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoIncidencia::class, 'tipo_incidencia_id');
    }

    /**
     * @return BelongsTo<Guardia, $this>
     */
    public function guardia(): BelongsTo
    {
        return $this->belongsTo(Guardia::class);
    }
}
