<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int|null $residencial_id
 * @property int|null $user_id
 * @property string $modulo
 * @property string $accion
 * @property string|null $entidad
 * @property int|null $entidad_id
 * @property array<string, mixed>|null $valor_anterior
 * @property array<string, mixed>|null $valor_nuevo
 * @property string|null $motivo
 * @property string|null $ip_address
 * @property Carbon $created_at
 * @property-read User|null $usuario
 */
#[Fillable(['residencial_id', 'user_id', 'modulo', 'accion', 'entidad', 'entidad_id', 'valor_anterior', 'valor_nuevo', 'motivo', 'ip_address'])]
class Auditoria extends Model
{
    /**
     * @var string
     */
    protected $table = 'auditoria';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * La bitácora solo admite registros nuevos
     */
    protected static function booted(): void
    {
        $rechazar = function (): void {
            throw new LogicException('La bitácora de auditoría no se puede modificar ni eliminar');
        };

        static::updating($rechazar);
        static::deleting($rechazar);
    }

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor_anterior' => 'array',
            'valor_nuevo' => 'array',
            'created_at' => 'datetime',
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
