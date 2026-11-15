<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $residencial_id
 * @property string $codigo
 * @property int $tipo_vivienda_id
 * @property int|null $vivienda_padre_id
 * @property string|null $edificio_torre
 * @property string|null $calle_bloque
 * @property string|null $numero
 * @property string $estado_ocupacion
 * @property bool $activa
 * @property-read TipoVivienda $tipoVivienda
 * @property-read Vivienda|null $padre
 */
#[Fillable(['residencial_id', 'codigo', 'tipo_vivienda_id', 'vivienda_padre_id', 'edificio_torre', 'calle_bloque', 'numero', 'estado_ocupacion', 'activa'])]
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
     * @return BelongsTo<TipoVivienda, $this>
     */
    public function tipoVivienda(): BelongsTo
    {
        return $this->belongsTo(TipoVivienda::class);
    }

    /**
     * Vivienda a la que pertenece, como el apartamento de una habitación
     *
     * @return BelongsTo<Vivienda, $this>
     */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(Vivienda::class, 'vivienda_padre_id');
    }

    /**
     * Viviendas que pertenecen a esta, como las habitaciones de un apartamento
     *
     * @return HasMany<Vivienda, $this>
     */
    public function hijas(): HasMany
    {
        return $this->hasMany(Vivienda::class, 'vivienda_padre_id');
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
