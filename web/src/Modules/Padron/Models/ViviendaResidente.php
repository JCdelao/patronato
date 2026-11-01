<?php

namespace Patronato\Modules\Padron\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Patronato\Shared\PerteneceAResidencial;

/**
 * @property int $id
 * @property int $residencial_id
 * @property int $vivienda_id
 * @property int $residente_id
 * @property string $tipo
 * @property bool $es_responsable_pago
 * @property Carbon|null $fecha_inicio
 * @property Carbon|null $fecha_fin
 * @property-read Residente $residente
 * @property-read Vivienda $vivienda
 */
#[Fillable(['residencial_id', 'vivienda_id', 'residente_id', 'tipo', 'es_responsable_pago', 'fecha_inicio', 'fecha_fin'])]
class ViviendaResidente extends Model
{
    use PerteneceAResidencial;

    /**
     * @var string
     */
    protected $table = 'vivienda_residentes';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'es_responsable_pago' => 'boolean',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Vivienda, $this>
     */
    public function vivienda(): BelongsTo
    {
        return $this->belongsTo(Vivienda::class);
    }

    /**
     * @return BelongsTo<Residente, $this>
     */
    public function residente(): BelongsTo
    {
        return $this->belongsTo(Residente::class);
    }
}
