<?php

namespace Patronato\Modules\Finanzas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Patronato\Shared\PerteneceAResidencial;

/**
 * @property int $id
 * @property int $residencial_id
 * @property int $cobrador_id
 * @property string $estado
 * @property Carbon $abierta_en
 * @property Carbon|null $cerrada_en
 */
#[Fillable(['residencial_id', 'cobrador_id', 'estado', 'abierta_en'])]
class Caja extends Model
{
    use PerteneceAResidencial;

    public const ABIERTA = 'abierta';

    /**
     * @var string
     */
    protected $table = 'cajas';

    /**
     * Conversiones de tipo de los atributos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abierta_en' => 'datetime',
            'cerrada_en' => 'datetime',
        ];
    }
}
