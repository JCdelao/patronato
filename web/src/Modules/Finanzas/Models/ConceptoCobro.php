<?php

namespace Patronato\Modules\Finanzas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Patronato\Shared\PerteneceAResidencial;

/**
 * @property int $id
 * @property int $residencial_id
 * @property string $nombre
 * @property string $tipo
 * @property bool $es_cuota_unificada
 * @property bool $activo
 */
#[Fillable(['residencial_id', 'nombre', 'tipo', 'es_cuota_unificada', 'activo'])]
class ConceptoCobro extends Model
{
    use PerteneceAResidencial;

    public const RECURRENTE = 'recurrente';

    public const UNICO = 'unico';

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
     * @return HasMany<Tarifa, $this>
     */
    public function tarifas(): HasMany
    {
        return $this->hasMany(Tarifa::class, 'concepto_id');
    }
}
