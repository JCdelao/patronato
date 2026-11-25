<?php

namespace Patronato\Persistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['residencial_id', 'vivienda_id', 'motivo', 'vence_en', 'autorizado_por'])]
class ExcepcionAcceso extends Model
{
    use PerteneceAResidencial;

    public const UPDATED_AT = null;

    protected $table = 'excepciones_acceso';

    protected function casts(): array
    {
        return ['vence_en' => 'immutable_datetime'];
    }
}
