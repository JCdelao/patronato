<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $residencial_id
 * @property string $nombre
 * @property string $tipo
 * @property string $alcance
 * @property bool $es_cuota_unificada
 * @property bool $activo
 */
#[Fillable(['residencial_id', 'nombre', 'tipo', 'alcance', 'es_cuota_unificada', 'activo'])]
class ConceptoCobro extends Model
{
    use PerteneceAResidencial;

    public const RECURRENTE = 'recurrente';

    public const UNICO = 'unico';

    /**
     * Se cobra a todas las viviendas activas
     */
    public const TODAS = 'todas';

    /**
     * Se cobra solo a las viviendas elegidas
     */
    public const ALGUNAS = 'algunas';

    /**
     * @var string
     */
    protected $table = 'conceptos_cobro';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'es_cuota_unificada' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Cargo, $this>
     */
    public function cargos(): HasMany
    {
        return $this->hasMany(Cargo::class, 'concepto_id');
    }

    /**
     * Viviendas elegidas cuando el concepto se cobra solo a algunas
     *
     * @return BelongsToMany<Vivienda, $this>
     */
    public function viviendas(): BelongsToMany
    {
        return $this->belongsToMany(Vivienda::class, 'concepto_viviendas', 'concepto_id', 'vivienda_id');
    }

    /**
     * @return HasMany<Tarifa, $this>
     */
    public function tarifas(): HasMany
    {
        return $this->hasMany(Tarifa::class, 'concepto_id');
    }
}
