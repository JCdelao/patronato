<?php

namespace Patronato\Modules\Plataforma\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Patronato\Modules\Identidad\Services\PermisoService;

/**
 * @property int $id
 * @property string $nombre
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $correo
 * @property string|null $logo_path
 * @property string $estado
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['nombre', 'direccion', 'telefono', 'correo', 'logo_path', 'estado'])]
class Residencial extends Model
{
    public const ACTIVO = 'activo';

    public const SUSPENDIDO = 'suspendido';

    /**
     * @var string
     */
    protected $table = 'residenciales';

    /**
     * Asigna los permisos iniciales al crear un residencial
     */
    protected static function booted(): void
    {
        static::created(function (Residencial $residencial): void {
            app(PermisoService::class)->asignarPredeterminados($residencial->id);
        });
    }
}
