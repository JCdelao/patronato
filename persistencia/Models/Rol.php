<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 */
class Rol extends Model
{
    public const ADMIN_PLATAFORMA = 'admin_plataforma';

    public const ADMIN_TECNICO = 'admin_tecnico';

    public const PRESIDENTE = 'presidente';

    public const SECRETARIO = 'secretario';

    public const RESIDENTE = 'residente';

    /**
     * @var string
     */
    protected $table = 'roles';

    /**
     * @var bool
     */
    public $timestamps = false;
}
