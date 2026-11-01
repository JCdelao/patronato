<?php

namespace Patronato\Modules\Padron\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Patronato\Shared\PerteneceAResidencial;

/**
 * @property int $id
 * @property int $residencial_id
 * @property string $codigo
 * @property string $tipo
 * @property string|null $calle_bloque
 * @property bool $activa
 */
#[Fillable(['residencial_id', 'codigo', 'tipo', 'calle_bloque', 'activa'])]
class Vivienda extends Model
{
    use PerteneceAResidencial;

    /**
     * @var string
     */
    protected $table = 'viviendas';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    /**
     * Los residentes que viven actualmente en la vivienda
     *
     * @return HasMany<ViviendaResidente, $this>
     */
    public function vinculosVigentes(): HasMany
    {
        return $this->hasMany(ViviendaResidente::class)->whereNull('fecha_fin');
    }
}
