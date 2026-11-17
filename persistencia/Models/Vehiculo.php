<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vehículo de una vivienda; la placa no se repite entre los activos del residencial
 *
 * @property int $id
 * @property int $residencial_id
 * @property int $vivienda_id
 * @property string $tipo
 * @property string $placa
 * @property string|null $marca
 * @property string|null $modelo
 * @property string|null $color
 * @property bool $activo
 * @property-read Vivienda $vivienda
 * @property-read Collection<int, VehiculoConductor> $conductores
 */
#[Fillable(['residencial_id', 'vivienda_id', 'tipo', 'placa', 'marca', 'modelo', 'color', 'activo'])]
class Vehiculo extends Model
{
    use PerteneceAResidencial;

    public const TIPOS = ['carro', 'moto', 'otro'];

    /**
     * @var string
     */
    protected $table = 'vehiculos';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
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
     * Personas autorizadas a conducirlo
     *
     * @return HasMany<VehiculoConductor, $this>
     */
    public function conductores(): HasMany
    {
        return $this->hasMany(VehiculoConductor::class);
    }
}
