<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Guardia de vigilancia del residencial; la identidad no se repite en el residencial
 *
 * @property int $id
 * @property int $residencial_id
 * @property int|null $user_id
 * @property string $nombre
 * @property string $identidad
 * @property string|null $telefono
 * @property string $turno
 * @property bool $activo
 * @property-read User|null $cuenta
 */
#[Fillable(['residencial_id', 'user_id', 'nombre', 'identidad', 'telefono', 'turno', 'activo'])]
class Guardia extends Model
{
    use PerteneceAResidencial;

    /**
     * Turno habitual; las fechas de cada turno se programan en el calendario
     */
    public const TURNOS = ['dia', 'tarde', 'noche', '24_horas', 'rotativo'];

    /**
     * @var string
     */
    protected $table = 'guardias';

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
     * Cuenta con la que el guardia entra al sistema
     *
     * @return BelongsTo<User, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
