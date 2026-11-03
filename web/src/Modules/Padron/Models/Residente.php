<?php

namespace Patronato\Modules\Padron\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Patronato\Shared\PerteneceAResidencial;

/**
 * @property int $id
 * @property int $residencial_id
 * @property int|null $user_id
 * @property string $nombres
 * @property string $apellidos
 * @property string|null $identidad
 * @property string|null $telefono
 * @property string|null $correo
 * @property bool $activo
 * @property-read Collection<int, ViviendaResidente> $vinculos
 * @property-read Collection<int, ViviendaResidente> $vinculosVigentes
 */
#[Fillable(['residencial_id', 'user_id', 'nombres', 'apellidos', 'identidad', 'telefono', 'correo', 'activo'])]
class Residente extends Model
{
    use PerteneceAResidencial;

    /**
     * @var string
     */
    protected $table = 'residentes';

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
     * Todas las viviendas donde ha vivido, con sus fechas
     *
     * @return HasMany<ViviendaResidente, $this>
     */
    public function vinculos(): HasMany
    {
        return $this->hasMany(ViviendaResidente::class);
    }

    /**
     * Las viviendas donde vive actualmente
     *
     * @return HasMany<ViviendaResidente, $this>
     */
    public function vinculosVigentes(): HasMany
    {
        return $this->hasMany(ViviendaResidente::class)->whereNull('fecha_fin');
    }
}
