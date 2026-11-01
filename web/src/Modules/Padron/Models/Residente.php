<?php

namespace Patronato\Modules\Padron\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property-read ViviendaResidente|null $vinculoVigente
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
     * Get the attributes that should be cast.
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
     * @return HasOne<ViviendaResidente, $this>
     */
    public function vinculoVigente(): HasOne
    {
        return $this->hasOne(ViviendaResidente::class)->whereNull('fecha_fin');
    }
}
