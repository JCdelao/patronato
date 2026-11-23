<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $token
 */
#[Fillable(['residencial_id', 'residente_id', 'token'])]
#[Hidden(['token'])]
class QrResidente extends Model
{
    use PerteneceAResidencial;

    public const UPDATED_AT = null;

    protected $table = 'qr_residentes';

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'invalidado_en' => 'datetime',
        ];
    }
}
