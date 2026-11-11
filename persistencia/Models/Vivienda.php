<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $residencial_id
 * @property string $codigo
 * @property string $tipo
 * @property string|null $edificio_torre
 * @property string|null $calle_bloque
 * @property string|null $numero
 * @property string $estado_ocupacion
 * @property bool $activa
 */
#[Fillable(['residencial_id', 'codigo', 'tipo', 'edificio_torre', 'calle_bloque', 'numero', 'estado_ocupacion', 'activa'])]
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
