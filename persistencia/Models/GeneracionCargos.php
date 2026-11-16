<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Registro de cada vez que se generaron los cargos de un período
 *
 * @property int $id
 * @property int $residencial_id
 * @property Carbon $periodo
 * @property int $cargos
 * @property bool $automatica
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property-read User|null $usuario
 */
#[Fillable(['residencial_id', 'periodo', 'cargos', 'automatica', 'user_id'])]
class GeneracionCargos extends Model
{
    use PerteneceAResidencial;

    public const UPDATED_AT = null;

    /**
     * @var string
     */
    protected $table = 'generaciones_cargos';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'periodo' => 'date',
            'automatica' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
